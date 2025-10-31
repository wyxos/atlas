<?php

namespace App\Services;

use App\Models\File;
use App\Models\FileMetadata;

class BrowsePersister
{
    /**
     * Persist transformed rows produced by a browse service.
     *
     * @return array<int, File> Persisted files that are ready for UI consumption.
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
            ['url', 'filename', 'ext', 'mime_type', 'description', 'thumbnail_url', 'listing_metadata', 'updated_at']
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

        return $allFiles->filter(function (File $file) {
            return ($file->downloaded === false || $file->downloaded === null)
                && ! $file->previewed_at
                && ! $file->blacklisted_at;
        })->values()->all();
    }
}
