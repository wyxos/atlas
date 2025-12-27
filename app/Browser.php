<?php

namespace App;

use App\Models\File;
use App\Services\BaseService;
use App\Services\BrowseModerationService;
use App\Services\BrowsePersister;
use App\Services\CivitAiImages;
use App\Services\FileItemFormatter;
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
            $params = $tab->params ?? [];
            $isLocalMode = isset($params['feed']) && $params['feed'] === 'local';
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
            ];
        } catch (\Throwable $e) {
            $serviceError = [
                'message' => 'Service error: '.$e->getMessage(),
                'status' => method_exists($e, 'getCode') ? $e->getCode() : null,
                'exception' => get_class($e),
            ];
            $response = [
                'items' => [],
            ];
        }

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
        // For local mode, files are already File models from LocalService.transform()
        // LocalService.fetch() already eager loads metadata, so no additional query needed
        if ($isLocalMode) {
            $persisted = $filesPayload;
        } else {
            $persisted = app(BrowsePersister::class)->persist($filesPayload);
        }

        // Eager load reactions for the current user
        if (auth()->check() && !empty($persisted)) {
            $fileIds = [];
            foreach ($persisted as $file) {
                if ($file instanceof \App\Models\File) {
                    $fileIds[] = $file->id;
                }
            }

            if (!empty($fileIds)) {
                $userReactions = \App\Models\Reaction::where('user_id', auth()->id())
                    ->whereIn('file_id', $fileIds)
                    ->get()
                    ->keyBy('file_id');

                // Attach reactions to files
                foreach ($persisted as $file) {
                    if ($file instanceof \App\Models\File) {
                        $reaction = $userReactions->get($file->id);
                        $file->setRelation('reaction', $reaction);
                    }
                }
            }
        }

        // Process moderation (file and container moderation, filtering, immediate actions)
        $moderationResult = app(BrowseModerationService::class)->process($persisted);
        $persisted = $moderationResult['files'];
        $flaggedIds = $moderationResult['flaggedIds'];
        $immediateActions = $moderationResult['immediateActions'];

        // Attach filtered files to tab if tab_id is provided and not in local mode
        // Local mode doesn't attach files to tabs as they get updated every time
        if ($tabId && ! $isLocalMode) {
            $this->attachFilesToTab($tabId, $persisted);
            // Update tab's params with current filter state (backend is responsible for this)
            // Store 'service' key (not 'source') to match frontend expectation
            $this->updateTabParams($tabId, [
                'service' => $source, // Store the service key as 'service' for frontend compatibility
                ...$service->defaultParams(),
                ...$filter,
                'page' => request()->input('page', 1),
                'next' => $filter['next'] ?? null,
            ]);
        }

        // Transform persisted files to items format for frontend
        // Pass flagged IDs so they get will_auto_dislike = true (includes both moderation and container blacklist)
        // Page can be int (page number) or string (cursor) for pagination tracking
        $page = request()->input('page', 1);
        $items = FileItemFormatter::format($persisted, $page, $flaggedIds);

        return [
            'items' => $items,
            'filter' => [
                ...$service->defaultParams(),
                ...$filter,
                'page' => request()->input('page', 1),
                'next' => $filter['next'] ?? null,
            ],
            'moderation' => $immediateActions,
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
     * Update a browse tab's params.
     *
     * @param  array<string, mixed>  $params
     */
    protected function updateTabParams(int $tabId, array $params): void
    {
        $tab = \App\Models\Tab::find($tabId);

        if (! $tab || $tab->user_id !== auth()->id()) {
            return; // Tab doesn't exist or user doesn't own it
        }

        // Update params with the provided params
        $tab->update([
            'params' => $params,
        ]);
    }
}
