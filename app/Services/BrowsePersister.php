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

        $fileRows = collect($normalized)->map(fn ($i) => $i['file'])->toArray();

        File::upsert(
            $fileRows,
            ['referrer_url'],
            ['url', 'filename', 'ext', 'mime_type', 'description', 'thumbnail_url', 'size', 'listing_metadata', 'updated_at']
        );

        $referrers = collect($normalized)->map(fn ($i) => $i['file']['referrer_url'])->filter()->values()->all();
        $fileMap = File::whereIn('referrer_url', $referrers)->get()->keyBy('referrer_url');

        $metaRows = collect($normalized)
            ->map(function ($i) use ($fileMap) {
                $meta = $i['metadata'];
                $ref = $meta['file_referrer_url'] ?? $i['file']['referrer_url'];
                $file = $fileMap->get($ref);
                if (! $file) {
                    return null;
                }

                return [
                    'file_id' => $file->id,
                    'payload' => $meta['payload'],
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

        $allFiles = File::with('metadata')->whereIn('referrer_url', $referrers)->get();

        // Create containers and attach files in batch
        $this->createContainersForFiles($allFiles);

        return $allFiles->filter(function (File $file) {
            return ($file->downloaded === false || $file->downloaded === null)
                && ! $file->previewed_at
                && ! $file->blacklisted_at
                && ! $file->auto_disliked;
        })->values()->all();
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

        foreach ($files as $file) {
            $listingMetadata = $file->listing_metadata;
            if (! is_array($listingMetadata)) {
                continue;
            }

            $source = (string) ($file->source ?? '');
            if ($source === '') {
                continue;
            }

            $fileContainers = [];

            // Process Post container
            if (isset($listingMetadata['postId'])) {
                $postId = (string) $listingMetadata['postId'];
                $containerKey = "Post:{$source}:{$postId}";

                if (! isset($containerData[$containerKey])) {
                    $referrer = $source === 'CivitAI' ? "https://civitai.com/posts/{$postId}" : null;
                    $containerData[$containerKey] = [
                        'type' => 'Post',
                        'source' => $source,
                        'source_id' => $postId,
                        'referrer' => $referrer,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                $fileContainers[] = $containerKey;
            }

            // Process User container
            if (isset($listingMetadata['username']) && is_string($listingMetadata['username'])) {
                $username = $listingMetadata['username'];
                $containerKey = "User:{$source}:{$username}";

                if (! isset($containerData[$containerKey])) {
                    $referrer = $source === 'CivitAI' ? "https://civitai.com/user/{$username}" : null;
                    $containerData[$containerKey] = [
                        'type' => 'User',
                        'source' => $source,
                        'source_id' => $username,
                        'referrer' => $referrer,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                $fileContainers[] = $containerKey;
            }

            if (! empty($fileContainers)) {
                $fileContainerMap[$file->id] = $fileContainers;
            }
        }

        if (empty($containerData)) {
            return;
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

        foreach ($fileContainerMap as $fileId => $containerKeys) {
            foreach ($containerKeys as $containerKey) {
                if (! isset($containers[$containerKey])) {
                    continue;
                }

                $containerId = $containers[$containerKey]->id;
                $pivotInserts[] = [
                    'file_id' => $fileId,
                    'container_id' => $containerId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // Batch insert pivot records (insertOrIgnore handles duplicates)
        if (! empty($pivotInserts)) {
            // Use chunking to avoid query size limits
            foreach (array_chunk($pivotInserts, 500) as $chunk) {
                DB::table('container_file')->insertOrIgnore($chunk);
            }
        }
    }
}
