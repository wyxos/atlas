<?php

namespace App;

use App\Models\File;
use App\Services\BrowseModerationService;
use App\Services\BrowsePersister;
use App\Services\CivitAiImages;
use App\Services\FileItemFormatter;
use App\Services\LocalService;
use App\Services\Wallhaven;
use Illuminate\Http\Client\ConnectionException;

class Browser
{
    /**
     * Resolve a source key and return the service transform result.
     *
     * Request inputs:
     * - service: service key (default: 'civit-ai-images')
     * - source: local-mode source filter (only when feed=local)
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
        // Check if this is a local tab
        $tabId = request()->input('tab_id');
        $feed = $params['feed'] ?? null;

        $isLocalMode = $feed === 'local';

        // Get available services
        $services = $this->getAvailableServices();

        // Prefer the canonical `service` query parameter.
        // Back-compat: some frontends send the selected service as `source`.
        // Only treat `source` as a service alias when NOT in local mode.
        $requestedService = (string) ($params['service'] ?? '');
        if ($requestedService === '' && ! $isLocalMode) {
            $sourceCandidate = (string) ($params['source'] ?? '');
            if ($sourceCandidate !== '' && array_key_exists($sourceCandidate, $services)) {
                $requestedService = $sourceCandidate;
            }
        }

        $serviceKey = $requestedService !== '' ? $requestedService : CivitAiImages::key();
        $servicesMeta = [];
        foreach ($services as $key => $serviceClass) {
            $serviceInstance = app($serviceClass);
            $servicesMeta[] = [
                'key' => $serviceInstance::key(),
                'label' => $serviceInstance::label(),
                'defaults' => $serviceInstance->defaultParams(),
            ];
        }

        // Track whether the incoming `source` param was used as a service alias.
        // If so, we must not persist it into the tab's local-mode `source` field.
        $sourceWasServiceAlias = ! $isLocalMode
            && $requestedService !== ''
            && (string) ($params['service'] ?? '') === ''
            && (string) ($params['source'] ?? '') === $requestedService;

        // If we're starting a new browse session (page=1), clear the tab's existing file attachments.
        // This keeps tab history consistent and prevents results from accumulating across new searches.
        // Note: we do this even if the upcoming fetch returns zero items.
        $pageParam = request()->input('page', 1);
        if ($tabId && (string) $pageParam === '1') {
            $this->detachFilesFromTab((int) $tabId);
        }

        // Resolve service instance
        // If local mode, use LocalService; otherwise use the selected service
        if ($isLocalMode) {
            $service = app(LocalService::class);
        } else {
            $serviceClass = $services[$serviceKey] ?? $services[CivitAiImages::key()] ?? CivitAiImages::class;
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
            $meta = $transformed['meta'] ?? [];
        } catch (\Throwable $e) {
            // If transform fails, use empty arrays
            $filesPayload = [];
            $filter = $params;
            $meta = [];
            if (! $serviceError) {
                $serviceError = [
                    'message' => 'Failed to process service response: '.$e->getMessage(),
                    'status' => null,
                    'exception' => get_class($e),
                ];
            }
        }

        // Only persist files if not in local mode (local files already exist in DB)
        // For local mode, files are already File models from LocalService.transform().
        // We intentionally lazy-load per-page relationships to avoid Scout total-count caps.
        if ($isLocalMode) {
            $persisted = $filesPayload;

            if (! empty($persisted)) {
                // Needed for moderation logic and UI details. Keep it per-page to avoid scanning the dataset.
                (new \Illuminate\Database\Eloquent\Collection($persisted))->load('metadata');
            }
        } else {
            $persisted = app(BrowsePersister::class)->persist($filesPayload);
        }

        // Eager load reactions for the current user
        if (auth()->check() && ! empty($persisted)) {
            $fileIds = [];
            foreach ($persisted as $file) {
                if ($file instanceof \App\Models\File) {
                    $fileIds[] = $file->id;
                }
            }

            if (! empty($fileIds)) {
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
        $localBlacklistFilter = $isLocalMode ? (string) ($params['blacklisted'] ?? 'any') : 'no';
        $shouldFilterBlacklisted = ! $isLocalMode || $localBlacklistFilter === 'no';

        $moderationResult = app(BrowseModerationService::class)->process($persisted, [
            'filterBlacklisted' => $shouldFilterBlacklisted,
        ]);
        $persisted = $moderationResult['files'];
        $flaggedIds = $moderationResult['flaggedIds'];
        $immediateActions = $moderationResult['immediateActions'];

        // Attach filtered files to tab if tab_id is provided and not in local mode
        // Local mode doesn't attach files to tabs as they get updated every time
        if ($tabId && ! $isLocalMode) {
            $this->attachFilesToTab($tabId, $persisted);
        }

        if ($tabId) {
            // Update tab's params with current filter state (backend is responsible for this)
            // Store 'service' key (not 'source') to match frontend expectation.

            $tab = \App\Models\Tab::query()
                ->where('id', (int) $tabId)
                ->where('user_id', auth()->id())
                ->first();

            if ($tab) {
                $existingParams = $tab->params;
                if (! is_array($existingParams)) {
                    $existingParams = [];
                }

                $existingServiceFilters = $existingParams['serviceFiltersByKey'] ?? [];
                if (! is_array($existingServiceFilters)) {
                    $existingServiceFilters = [];
                }

                // Persist the current page token; keep the next token separately.
                $pageToPersist = request()->input('page', 1);
                $nextToPersist = $filter['next'] ?? null;

                // Canonical UI filter state for this service.
                // Keep global keys (page/limit) and service-specific keys, but exclude non-filter envelope keys.
                $reserved = [
                    'service' => true,
                    'feed' => true,
                    'source' => true,
                    'tab_id' => true,
                    'serviceFiltersByKey' => true,
                    'page' => true,
                    'limit' => true,
                    'next' => true,
                ];

                $serviceEntry = [];
                foreach ([...$service->defaultParams(), ...$filter] as $k => $v) {
                    if (isset($reserved[$k])) {
                        continue;
                    }
                    $serviceEntry[$k] = $v;
                }

                // Always sync current pagination state into the per-service entry.
                $serviceEntry['page'] = $pageToPersist;
                $serviceEntry['limit'] = $filter['limit'] ?? request()->input('limit', $service->defaultParams()['limit'] ?? 20);

                // Only keep per-service filters for online services.
                if (! $isLocalMode) {
                    $existingServiceFilters[$serviceKey] = $serviceEntry;
                }

                $flatFilter = $filter;
                unset($flatFilter['next']);
                unset($flatFilter['page']);
                unset($flatFilter['limit']);

                $tab->update([
                    'params' => [
                        // Active selection envelope
                        'service' => $serviceKey,
                        'feed' => $isLocalMode ? 'local' : 'online',
                        // Keep local source around (used by local mode UI)
                        'source' => $isLocalMode
                            ? ($params['source'] ?? ($existingParams['source'] ?? 'all'))
                            : ($existingParams['source'] ?? 'all'),

                        ...$service->defaultParams(),
                        ...$flatFilter,
                        // Persist the next token to load.
                        'page' => $pageToPersist,
                        'next' => $nextToPersist,
                        // Per-service cache
                        'serviceFiltersByKey' => $existingServiceFilters,
                    ],
                ]);
            }
        }

        // Transform persisted files to items format for frontend
        // Pass flagged IDs so they get will_auto_dislike = true (includes both moderation and container blacklist)
        // Page can be int (page number) or string (cursor) for pagination tracking
        $page = request()->input('page', 1);
        $items = FileItemFormatter::format($persisted, $page, $flaggedIds);

        return [
            'items' => $items,
            'meta' => is_array($meta) ? $meta : [],
            'filter' => [
                'service' => $serviceKey, // Store the service key as 'service' for frontend compatibility
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
     * Detach all files currently attached to a browse tab.
     */
    protected function detachFilesFromTab(int $tabId): void
    {
        $tab = \App\Models\Tab::find($tabId);

        if (! $tab || $tab->user_id !== auth()->id()) {
            return;
        }

        $tab->files()->detach();
    }
}
