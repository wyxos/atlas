<?php

namespace App;

use App\Models\File;
use App\Services\BaseService;
use App\Services\BrowsePersister;
use App\Services\CivitAiImages;
use App\Services\ContainerModerationService;
use App\Services\FileItemFormatter;
use App\Services\FileModerationService;
use App\Services\LocalService;
use App\Services\Wallhaven;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Storage;

class Browser
{
    /**
     * Resolve a source key and return the service transform result.
     *
     * Request inputs:
     * - source: service key (default: 'civit-ai-images')
     * - page: page number for pagination
     * - any other inputs are forwarded to fetch()
     */
    public static function handle(): array
    {
        return (new self)->run();
    }

    /**
     * @throws ConnectionException
     */
    public function run(): array
    {
        $params = request()->all();
        $source = (string) ($params['source'] ?? CivitAiImages::key());

        // Get available services
        $services = $this->getAvailableServices();
        $servicesMeta = [];
        foreach ($services as $key => $serviceClass) {
            $serviceInstance = app($serviceClass);
            $servicesMeta[] = [
                'key' => $serviceInstance::key(),
                'label' => $serviceInstance::label(),
                'defaults' => $serviceInstance->defaultParams(),
            ];
        }

        // Check if this is a local/offline tab
        $tabId = request()->input('tab_id');
        $isLocalMode = false;
        if ($tabId) {
            $tab = \App\Models\Tab::find($tabId);
            $isLocalMode = $tab && $tab->source_type === 'offline';
        }

        // Resolve service instance
        // If local mode, use LocalService; otherwise use the selected service
        if ($isLocalMode) {
            $service = app(LocalService::class);
        } else {
            $serviceClass = $services[$source] ?? $services[CivitAiImages::key()] ?? CivitAiImages::class;
            $service = app($serviceClass);
        }

        if (method_exists($service, 'setParams')) {
            $service->setParams($params);
        }

        $serviceError = null;
        $response = null;

        try {
            $response = $service->fetch($params);

            // Check if response is empty or invalid (service might be down)
            if (! is_array($response) || empty($response)) {
                $serviceError = [
                    'message' => 'Service returned an empty response',
                    'status' => null,
                ];
                $response = [
                    'items' => [],
                    'metadata' => [
                        'nextCursor' => null,
                    ],
                ];
            }
        } catch (ConnectionException $e) {
            $serviceError = [
                'message' => 'Unable to connect to service',
                'status' => null,
                'exception' => $e->getMessage(),
            ];
            $response = [
                'items' => [],
                'metadata' => [
                    'nextCursor' => null,
                ],
            ];
        } catch (\Throwable $e) {
            $serviceError = [
                'message' => 'Service error: '.$e->getMessage(),
                'status' => method_exists($e, 'getCode') ? $e->getCode() : null,
                'exception' => get_class($e),
            ];
            $response = [
                'items' => [],
                'metadata' => [
                    'nextCursor' => null,
                ],
            ];
        }

        Storage::disk('local')->put('temp/'.time().'-response.json', json_encode($response, JSON_PRETTY_PRINT));

        try {
            $transformed = $service->transform($response, $params);
            $filesPayload = $transformed['files'] ?? [];
            $filter = $transformed['filter'] ?? [];
        } catch (\Throwable $e) {
            // If transform fails, use empty arrays
            $filesPayload = [];
            $filter = $params;
            if (! $serviceError) {
                $serviceError = [
                    'message' => 'Failed to process service response: '.$e->getMessage(),
                    'status' => null,
                    'exception' => get_class($e),
                ];
            }
        }

        // Only persist files if not in local mode (local files already exist in DB)
        // For local mode, convert transformed format back to File models for moderation
        if ($isLocalMode) {
            // Extract file referrer URLs from transformed format
            $referrers = collect($filesPayload)->map(function ($item) {
                return $item['file']['referrer_url'] ?? null;
            })->filter()->values()->all();

            // Load File models with metadata
            $persisted = File::with('metadata')->whereIn('referrer_url', $referrers)->get()->all();
        } else {
            $persisted = app(BrowsePersister::class)->persist($filesPayload);
        }

        // File moderation: apply rules based on action_type
        $fileModerationResult = app(FileModerationService::class)->moderate(collect($persisted));
        $flaggedIds = $fileModerationResult['flaggedIds']; // Files matching rules with 'dislike' action type
        $processedIds = $fileModerationResult['processedIds'];
        $immediateActions = $fileModerationResult['immediateActions'] ?? [];

        // Container moderation: apply container moderation rules after file moderation
        $containerModerationResult = app(ContainerModerationService::class)->moderate(collect($persisted));
        $containerFlaggedIds = $containerModerationResult['flaggedIds']; // Files matching container blacklist with 'dislike' action type
        $containerProcessedIds = $containerModerationResult['processedIds'];
        $containerImmediateActions = $containerModerationResult['immediateActions'] ?? [];

        // Merge flagged file IDs from both moderation rules and container blacklist rules
        // Both include files that match rules with 'dislike' action type (will show will_auto_dislike = true in UI)
        $flaggedIds = array_merge($flaggedIds, $containerFlaggedIds);

        // Merge processed IDs from both moderation and container blacklist
        $processedIds = array_merge($processedIds, $containerProcessedIds);

        // Merge immediate actions from both moderation and container blacklist
        $immediateActions = array_merge($immediateActions, $containerImmediateActions);

        // Store files before filtering (needed for immediate actions formatting)
        $allFilesBeforeFilter = collect($persisted)->keyBy('id');

        // Filter out processed files (auto-disliked or blacklisted) from response
        // This includes files that were processed in this request (via processedIds)
        // and files that were already auto-disliked/blacklisted (defensive check)
        $persisted = collect($persisted)->reject(function ($file) use ($processedIds) {
            // Filter if file was processed in this request
            if (in_array($file->id, $processedIds, true)) {
                return true;
            }

            // Defensive check: filter if file is already auto-disliked or blacklisted
            // (refresh from DB to get latest state, as model instances may be stale)
            $fresh = $file->fresh();

            return $fresh && ($fresh->auto_disliked || $fresh->blacklisted_at !== null);
        })->values()->all();

        // Attach filtered files to tab if tab_id is provided and not in local mode
        // Local mode doesn't attach files to tabs as they get updated every time
        if ($tabId && ! $isLocalMode) {
            $this->attachFilesToTab($tabId, $persisted);
            // Update tab's query_params with current filter state (backend is responsible for this)
            // Store 'service' key (not 'source') to match frontend expectation
            $this->updateTabQueryParams($tabId, [
                'service' => $source, // Store the service key as 'service' for frontend compatibility
                ...$service->defaultParams(),
                ...$filter,
                'page' => request()->input('page', 1),
                'next' => $filter['next'] ?? null,
            ]);
        }

        // Transform persisted files to items format for frontend
        // Pass flagged IDs so they get will_auto_dislike = true (includes both moderation and container blacklist)
        // Use minimal format for virtualization (load full data on-demand)
        $page = (int) (request()->input('page', 1));
        $minimal = request()->boolean('minimal', true); // Default to minimal for performance
        $items = FileItemFormatter::format($persisted, $page, $flaggedIds, $minimal);

        // Format immediately processed files (auto-disliked/blacklisted) for frontend toast notifications
        // These are files that were immediately processed (before they were filtered out)
        $immediatelyProcessedFiles = [];
        if (! empty($immediateActions)) {
            // Extract file IDs and create action_type map
            $immediateFileIds = array_column($immediateActions, 'file_id');
            $actionTypeMap = array_column($immediateActions, 'action_type', 'file_id');

            // Filter files directly from collection and map to desired structure
            $immediatelyProcessedFiles = $allFilesBeforeFilter
                ->only($immediateFileIds)
                ->map(fn ($file) => [
                    'id' => $file->id,
                    'action_type' => $actionTypeMap[$file->id] ?? 'dislike',
                    'thumbnail' => $file->thumbnail_url ?? $file->url,
                ])
                ->values()
                ->all();
        }

        return [
            'items' => $items,
            'filter' => [
                ...$service->defaultParams(),
                ...$filter,
                'page' => request()->input('page', 1),
                'next' => $filter['next'] ?? null,
            ],
            'moderation' => $immediatelyProcessedFiles,
            'error' => $serviceError,
            'services' => $servicesMeta,
        ];
    }

    /**
     * Get available browse services.
     *
     * @return array<string, class-string<BaseService>>
     */
    protected function getAvailableServices(): array
    {
        return [
            CivitAiImages::key() => CivitAiImages::class,
            Wallhaven::key() => Wallhaven::class,
            LocalService::key() => LocalService::class,
        ];
    }

    /**
     * Attach files to a browse tab with positions.
     *
     * @param  array<int, \App\Models\File>  $files
     */
    protected function attachFilesToTab(int $tabId, array $files): void
    {
        if (empty($files)) {
            return; // Nothing to attach
        }

        $tab = \App\Models\Tab::find($tabId);

        if (! $tab || $tab->user_id !== auth()->id()) {
            return; // Tab doesn't exist or user doesn't own it
        }

        // Get current highest position in tab
        $maxPosition = $tab->files()->max('tab_file.position') ?? -1;

        // Prepare sync data with positions
        $syncData = [];
        foreach ($files as $index => $file) {
            if (! $file instanceof \App\Models\File) {
                continue; // Skip invalid entries
            }
            $position = $maxPosition + 1 + $index;
            $syncData[$file->id] = ['position' => $position];
        }

        if (empty($syncData)) {
            return; // No valid files to attach
        }

        // Sync files to tab (without detaching existing files)
        $tab->files()->syncWithoutDetaching($syncData);
    }

    /**
     * Update a browse tab's query_params.
     *
     * @param  array<string, mixed>  $queryParams
     */
    protected function updateTabQueryParams(int $tabId, array $queryParams): void
    {
        $tab = \App\Models\Tab::find($tabId);

        if (! $tab || $tab->user_id !== auth()->id()) {
            return; // Tab doesn't exist or user doesn't own it
        }

        // Update query_params with the provided params
        $tab->update([
            'query_params' => $queryParams,
        ]);
    }
}
