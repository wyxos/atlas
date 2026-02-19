<?php

namespace App\Services;

use App\Models\Container;
use App\Models\File;
use App\Models\FileMetadata;
use Illuminate\Support\Facades\DB;

class BrowsePersister
{
    /**
     * Persist transformed rows produced by a browse service.
     */
    public function persist(array $transformedItems): array
    {
        if (empty($transformedItems)) {
            return [];
        }

        $normalized = array_values(array_filter($transformedItems, function ($item) {
            return isset($item['file']) && isset($item['metadata']);
        }));

        $preparedItems = collect($normalized)
            ->map(function (array $item): array {
                $file = $item['file'];
                $canonicalUrl = $this->resolveCanonicalUrlFromFileRow($file);
                if ($canonicalUrl !== '') {
                    $file['url'] = $canonicalUrl;
                }
                if (array_key_exists('listing_metadata', $file) && is_array($file['listing_metadata'])) {
                    $file['listing_metadata'] = json_encode($file['listing_metadata']);
                }

                return [
                    'file' => $file,
                    'metadata' => $item['metadata'],
                    'url' => $canonicalUrl,
                ];
            })
            ->filter(fn (array $item): bool => is_string($item['url'] ?? null) && ($item['url'] ?? '') !== '')
            ->values()
            ->all();
        if (empty($preparedItems)) {
            return [];
        }

        $urls = collect($preparedItems)
            ->pluck('url')
            ->filter(fn ($value) => is_string($value) && $value !== '')
            ->unique()
            ->values()
            ->all();
        $existingUrls = ! empty($urls)
            ? File::whereIn('url', $urls)->pluck('url')->all()
            : [];
        $newFileCount = 0;
        if (! empty($urls)) {
            $newFileCount = count(array_diff($urls, $existingUrls));
        }

        $fileRows = collect($preparedItems)->pluck('file')->all();

        File::upsert(
            $fileRows,
            ['url'],
            ['url', 'referrer_url', 'filename', 'ext', 'mime_type', 'description', 'preview_url', 'size', 'listing_metadata', 'updated_at']
        );

        if ($newFileCount > 0) {
            $metrics = app(MetricsService::class);
            $metrics->incrementMetric(MetricsService::KEY_FILES_TOTAL, $newFileCount);
            $metrics->incrementMetric(MetricsService::KEY_FILES_UNREACTED_NOT_BLACKLISTED, $newFileCount);
        }

        $fileMap = File::whereIn('url', $urls)->get()->keyBy('url');

        $metaRows = collect($preparedItems)
            ->map(function ($i) use ($fileMap) {
                $meta = $i['metadata'];
                $url = is_string($i['url'] ?? null) ? $i['url'] : '';
                if ($url === '') {
                    return null;
                }

                $file = $fileMap->get($url);
                if (! $file) {
                    return null;
                }

                $payload = $meta['payload'];
                if (is_array($payload)) {
                    $payload = json_encode($payload);
                }

                return [
                    'file_id' => $file->id,
                    'payload' => $payload,
                    'created_at' => $meta['created_at'] ?? now(),
                    'updated_at' => $meta['updated_at'] ?? now(),
                ];
            })
            ->filter()
            ->values()
            ->all();

        if (! empty($metaRows)) {
            FileMetadata::upsert($metaRows, ['file_id'], ['payload', 'updated_at']);
        }

        $allFiles = File::with('metadata')->whereIn('url', $urls)->get();

        // Create containers and attach files in batch
        $this->createContainersForFiles($allFiles);

        return $allFiles->filter(function (File $file) {
            return ($file->downloaded === false || $file->downloaded === null)
                && ! $file->previewed_at
                && ! $file->blacklisted_at
                && ! $file->auto_disliked;
        })->values()->all();
    }

    private function resolveCanonicalUrlFromFileRow(array $file): string
    {
        $candidate = trim((string) ($file['url'] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }

        $candidate = trim((string) ($file['referrer_url'] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }

        return '';
    }

    /**
     * Create containers for files based on their listing_metadata and attach them in batch.
     */
    protected function createContainersForFiles($files): void
    {
        if ($files->isEmpty()) {
            return;
        }

        $containerData = [];
        $fileContainerMap = []; // Maps file_id => [container_keys]
        $serviceCache = [];
        $metrics = app(MetricsService::class);

        foreach ($files as $file) {
            $listingMetadata = $file->listing_metadata;
            if (! is_array($listingMetadata)) {
                continue;
            }

            $source = (string) ($file->source ?? '');
            if ($source === '') {
                continue;
            }

            if (! isset($serviceCache[$source])) {
                $serviceCache[$source] = $this->resolveServiceForSource($source);
            }

            $service = $serviceCache[$source];
            $fileContainers = [];

            $containers = $service->containers($listingMetadata, $file->detail_metadata ?? []);
            if (empty($containers)) {
                $containers = $this->fallbackContainers($listingMetadata);
            }

            foreach ($containers as $container) {
                $type = $container['type'] ?? null;
                $sourceId = $container['source_id'] ?? null;
                if (! is_string($type) || $type === '' || $sourceId === null || $sourceId === '') {
                    continue;
                }

                $sourceId = (string) $sourceId;
                $containerKey = "{$type}:{$source}:{$sourceId}";

                if (! isset($containerData[$containerKey])) {
                    $containerData[$containerKey] = [
                        'type' => $type,
                        'source' => $source,
                        'source_id' => $sourceId,
                        'referrer' => $container['referrer'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                $fileContainers[] = $containerKey;
            }

            if (! empty($fileContainers)) {
                $fileContainerMap[$file->id] = array_values(array_unique($fileContainers));
            }
        }

        if (empty($containerData)) {
            return;
        }

        $existingContainers = Container::query()
            ->where(function ($query) use ($containerData) {
                foreach ($containerData as $data) {
                    $query->orWhere(function ($q) use ($data) {
                        $q->where('type', $data['type'])
                            ->where('source', $data['source'])
                            ->where('source_id', $data['source_id']);
                    });
                }
            })
            ->get();

        $existingKeys = $existingContainers
            ->mapWithKeys(function ($container) {
                $key = "{$container->type}:{$container->source}:{$container->source_id}";

                return [$key => true];
            })
            ->all();

        $newContainerCount = 0;
        foreach ($containerData as $key => $data) {
            if (isset($existingKeys[$key])) {
                continue;
            }
            if (($data['type'] ?? null) === 'Post') {
                continue;
            }
            $newContainerCount += 1;
        }
        if ($newContainerCount > 0) {
            $metrics->incrementMetric(MetricsService::KEY_CONTAINERS_TOTAL, $newContainerCount);
        }

        // Upsert containers in batch
        $containerRows = array_values($containerData);
        Container::upsert(
            $containerRows,
            ['source', 'source_id'], // Unique constraint columns
            ['type', 'referrer', 'updated_at'] // Columns to update if exists
        );

        // Get all created/updated containers
        $containerKeys = array_keys($containerData);
        $containers = Container::query()
            ->where(function ($query) use ($containerData) {
                foreach ($containerData as $key => $data) {
                    $query->orWhere(function ($q) use ($data) {
                        $q->where('type', $data['type'])
                            ->where('source', $data['source'])
                            ->where('source_id', $data['source_id']);
                    });
                }
            })
            ->get()
            ->keyBy(function ($container) use ($containerData) {
                foreach ($containerData as $key => $data) {
                    if ($container->type === $data['type']
                        && $container->source === $data['source']
                        && $container->source_id === $data['source_id']) {
                        return $key;
                    }
                }

                return null;
            })
            ->filter();

        // Build pivot table inserts
        $pivotInserts = [];
        $now = now();
        $fileIds = array_keys($fileContainerMap);
        $containerIds = $containers->pluck('id')->all();
        $existingPairs = [];

        if (! empty($fileIds) && ! empty($containerIds)) {
            $existingPairs = DB::table('container_file')
                ->whereIn('file_id', $fileIds)
                ->whereIn('container_id', $containerIds)
                ->get(['file_id', 'container_id'])
                ->map(fn ($row) => "{$row->file_id}:{$row->container_id}")
                ->all();
        }
        $existingPairMap = array_flip($existingPairs);

        $fileMap = $files->keyBy('id');
        $favoritedFileIds = [];
        if (! empty($fileIds)) {
            $favoritedFileIds = \App\Models\Reaction::query()
                ->whereIn('file_id', $fileIds)
                ->where('type', 'love')
                ->distinct()
                ->pluck('file_id')
                ->map(fn ($value) => (int) $value)
                ->all();
        }
        $favoritedFileMap = array_flip($favoritedFileIds);

        $totalCounts = [];
        $downloadedCounts = [];
        $blacklistedCounts = [];
        $favoritedCounts = [];

        foreach ($fileContainerMap as $fileId => $containerKeys) {
            foreach ($containerKeys as $containerKey) {
                if (! isset($containers[$containerKey])) {
                    continue;
                }

                $containerId = $containers[$containerKey]->id;
                $pairKey = "{$fileId}:{$containerId}";
                if (isset($existingPairMap[$pairKey])) {
                    continue;
                }

                $pivotInserts[] = [
                    'file_id' => $fileId,
                    'container_id' => $containerId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $existingPairMap[$pairKey] = true;

                $totalCounts[$containerId] = ($totalCounts[$containerId] ?? 0) + 1;

                $file = $fileMap->get($fileId);
                if ($file && $file->downloaded) {
                    $downloadedCounts[$containerId] = ($downloadedCounts[$containerId] ?? 0) + 1;
                }
                if ($file && $file->blacklisted_at !== null) {
                    $blacklistedCounts[$containerId] = ($blacklistedCounts[$containerId] ?? 0) + 1;
                }
                if (isset($favoritedFileMap[$fileId])) {
                    $favoritedCounts[$containerId] = ($favoritedCounts[$containerId] ?? 0) + 1;
                }
            }
        }

        // Batch insert pivot records (duplicates already filtered)
        if (! empty($pivotInserts)) {
            // Use chunking to avoid query size limits
            foreach (array_chunk($pivotInserts, 500) as $chunk) {
                DB::table('container_file')->insertOrIgnore($chunk);
            }

            $metrics->incrementContainersByCounts('files_total', $totalCounts);
            $metrics->incrementContainersByCounts('files_downloaded', $downloadedCounts);
            $metrics->incrementContainersByCounts('files_blacklisted', $blacklistedCounts);
            $metrics->incrementContainersByCounts('files_favorited', $favoritedCounts);
        }
    }

    protected function resolveServiceForSource(string $source): BaseService
    {
        $browser = new \App\Browser;
        $reflection = new \ReflectionClass($browser);
        $method = $reflection->getMethod('getAvailableServices');
        $method->setAccessible(true);
        $services = $method->invoke($browser);

        foreach ($services as $key => $serviceClass) {
            $serviceInstance = app($serviceClass);
            if ($serviceInstance::source() === $source) {
                return $serviceInstance;
            }
            if ($key === $source) {
                return $serviceInstance;
            }
        }

        return app(LocalService::class);
    }

    private function fallbackContainers(array $listingMetadata): array
    {
        $containers = [];

        if (isset($listingMetadata['postId'])) {
            $postId = (string) $listingMetadata['postId'];
            if ($postId !== '') {
                $containers[] = [
                    'type' => 'Post',
                    'source_id' => $postId,
                    'referrer' => null,
                ];
            }
        }

        if (isset($listingMetadata['username']) && is_string($listingMetadata['username'])) {
            $username = trim($listingMetadata['username']);
            if ($username !== '') {
                $containers[] = [
                    'type' => 'User',
                    'source_id' => $username,
                    'referrer' => null,
                ];
            }
        }

        return $containers;
    }
}
