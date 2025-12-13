<?php

namespace App\Services;

use App\Models\Container;
use App\Models\File;
use Illuminate\Database\Eloquent\Collection;

class FileItemFormatter
{
    /**
     * Format files into items structure for frontend.
     *
     * @param  Collection<int, File>|array<int, File>  $files
     * @return array<int, array<string, mixed>>
     */
    public static function format($files, int $page = 1): array
    {
        // Eager load containers relationship to avoid N+1 queries
        if ($files instanceof Collection) {
            $files->load('containers');
        } elseif (is_array($files) && ! empty($files)) {
            $fileIds = array_map(fn (File $file) => $file->id, $files);
            $filesWithContainers = File::query()
                ->whereIn('id', $fileIds)
                ->with('containers')
                ->get()
                ->keyBy('id');

            // Update files in array with loaded containers
            foreach ($files as $file) {
                if (isset($filesWithContainers[$file->id])) {
                    $file->setRelation('containers', $filesWithContainers[$file->id]->containers);
                }
            }
        }

        $items = [];
        $index = 0;

        foreach ($files as $file) {
            $metadata = is_array($file->metadata?->payload) ? $file->metadata->payload : (is_string($file->metadata?->payload) ? json_decode($file->metadata->payload, true) : []);
            $listingMetadata = is_array($file->listing_metadata) ? $file->listing_metadata : (is_string($file->listing_metadata) ? json_decode($file->listing_metadata, true) : []);

            // Ensure containers relation is loaded (even if empty)
            if (! $file->relationLoaded('containers')) {
                $file->load('containers');
            }

            $containers = $file->containers->map(fn (Container $container) => [
                'id' => $container->id,
                'type' => $container->type,
                'source' => $container->source,
                'source_id' => $container->source_id,
                'referrer' => $container->referrer,
            ])->values()->all();

            $item = [
                'id' => $file->id,
                'width' => (int) ($metadata['width'] ?? 500),
                'height' => (int) ($metadata['height'] ?? 500),
                'src' => $file->thumbnail_url ?? $file->url,
                'originalUrl' => $file->url,
                'thumbnail' => $file->thumbnail_url,
                'type' => str_starts_with($file->mime_type ?? '', 'video/') ? 'video' : 'image',
                'page' => $page,
                'key' => "{$page}-{$file->id}",
                'index' => $index,
                'notFound' => false,
                'previewed_count' => $file->previewed_count ?? 0,
                'seen_count' => $file->seen_count ?? 0,
                'auto_disliked' => $file->auto_disliked ?? false,
                'metadata' => $metadata,
                'listing_metadata' => $listingMetadata,
                'containers' => $containers,
            ];

            $items[] = $item;
            $index++;
        }

        return $items;
    }
}
