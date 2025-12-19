<?php

namespace App;

use App\Services\BaseService;
use App\Services\BrowsePersister;
use App\Services\CivitAiImages;
use App\Services\ContainerModerationService;
use App\Services\FileItemFormatter;
use App\Services\FileModerationService;
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

        // Resolve service instance
        $serviceClass = $services[$source] ?? $services[CivitAiImages::key()] ?? CivitAiImages::class;
        $service = app($serviceClass);

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

        $persisted = app(BrowsePersister::class)->persist($filesPayload);

        // File moderation: apply rules based on action_type
        $fileModerationResult = app(FileModerationService::class)->moderate(collect($persisted));
        $flaggedIds = $fileModerationResult['flaggedIds'];
        $processedIds = $fileModerationResult['processedIds'];
        $immediateActions = $fileModerationResult['immediateActions'] ?? [];

        // Container moderation: apply container moderation rules after file moderation
        $containerModerationResult = app(ContainerModerationService::class)->moderate(collect($persisted));
        $containerFlaggedIds = $containerModerationResult['flaggedIds'];
        $containerProcessedIds = $containerModerationResult['processedIds'];
        $containerImmediateActions = $containerModerationResult['immediateActions'] ?? [];

        // Merge flagged file IDs from both moderation and container blacklist (both use same auto-dislike queue)
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

        // Attach filtered files to tab if tab_id is provided
        $tabId = request()->input('tab_id');
        if ($tabId) {
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

        // Format immediate actions with file information for frontend toast
        $immediateActionItems = [];
        if (! empty($immediateActions)) {
            // Get files that were immediately processed (before they were filtered out)
            // Use allFilesBeforeFilter which contains files before filtering
            foreach ($immediateActions as $action) {
                $file = $allFilesBeforeFilter->get($action['file_id']);
                if ($file) {
                    $immediateActionItems[] = [
                        'id' => $file->id,
                        'action_type' => $action['action_type'],
                        'thumbnail' => $file->thumbnail_url ?? $file->url,
                    ];
                }
            }
        }

        return [
            'items' => $items,
            'filter' => [
                ...$service->defaultParams(),
                ...$filter,
                'page' => request()->input('page', 1),
                'next' => $filter['next'] ?? null,
            ],
            'moderation' => [
                'flagged_count' => count($flaggedIds),
                'flagged_ids' => $flaggedIds,
                'processed_count' => count($processedIds),
                'processed_ids' => $processedIds,
                'immediate_actions' => $immediateActionItems,
            ],
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

        $browseTab = \App\Models\BrowseTab::find($tabId);

        if (! $browseTab || $browseTab->user_id !== auth()->id()) {
            return; // Tab doesn't exist or user doesn't own it
        }

        // Get current highest position in tab
        $maxPosition = $browseTab->files()->max('browse_tab_file.position') ?? -1;

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
        $browseTab->files()->syncWithoutDetaching($syncData);
    }

    /**
     * Update a browse tab's query_params.
     *
     * @param  array<string, mixed>  $queryParams
     */
    protected function updateTabQueryParams(int $tabId, array $queryParams): void
    {
        $browseTab = \App\Models\BrowseTab::find($tabId);

        if (! $browseTab || $browseTab->user_id !== auth()->id()) {
            return; // Tab doesn't exist or user doesn't own it
        }

        // Update query_params with the provided params
        $browseTab->update([
            'query_params' => $queryParams,
        ]);
    }
}
