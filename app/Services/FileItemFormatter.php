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
     * @param  array<int>  $willAutoDislikeIds  IDs of files to flag with will_auto_dislike = true (includes both moderation and container blacklist)
     * @param  bool  $minimal  If true, return only essential layout data (id, width, height, src, key, index) for virtualization
     * @return array<int, array<string, mixed>>
     */
    public static function format($files, int $page = 1, array $willAutoDislikeIds = [], bool $minimal = false): array
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
            // Only extract essential metadata properties needed for masonry display
            // Full metadata will be loaded on-demand when needed (e.g., in FileViewer)
            $metadata = is_array($file->metadata?->payload) ? $file->metadata->payload : (is_string($file->metadata?->payload) ? json_decode($file->metadata->payload, true) : []);

            // Extract only width/height from metadata (needed for masonry layout)
            $width = (int) ($metadata['width'] ?? 500);
            $height = (int) ($metadata['height'] ?? 500);

            if ($minimal) {
                // Minimal format: only essential layout data for virtualization
                // Full data will be loaded on-demand when items come into viewport
                // NOTE: originalUrl and containers are included because they're needed for:
                // - FileViewer to show original images (not thumbnails)
                // - Container badges to display and allow reactions
                // These are relatively small properties, so including them doesn't significantly impact performance

                // Ensure containers relation is loaded (even if empty)
                if (! $file->relationLoaded('containers')) {
                    $file->load('containers');
                }

                $containers = $file->containers->map(function (Container $container) {
                    return [
                        'id' => $container->id,
                        'type' => $container->type,
                        'source' => $container->source,
                        'source_id' => $container->source_id,
                        'referrer' => $container->referrer,
                        'action_type' => $container->action_type,
                        'blacklisted_at' => $container->blacklisted_at?->toIso8601String(),
                    ];
                })->values()->all();

                $item = [
                    'id' => $file->id,
                    'width' => $width,
                    'height' => $height,
                    'src' => $file->thumbnail_url ?? $file->url,
                    'originalUrl' => $file->url, // Needed for FileViewer to show original images
                    'thumbnail' => $file->thumbnail_url,
                    'type' => str_starts_with($file->mime_type ?? '', 'video/') ? 'video' : 'image',
                    'page' => $page,
                    'key' => "{$page}-{$file->id}",
                    'index' => $index,
                    'notFound' => false,
                    'previewed_count' => $file->previewed_count ?? 0,
                    'seen_count' => $file->seen_count ?? 0,
                    'auto_disliked' => $file->auto_disliked ?? false,
                    'will_auto_dislike' => in_array($file->id, $willAutoDislikeIds, true),
                    'containers' => $containers, // Needed for container badges and reactions
                ];
            } else {
                // Full format: include all properties
                // Extract prompt if available (used by usePromptData, but will fallback to API if missing)
                $prompt = $metadata['prompt'] ?? null;

                // Ensure containers relation is loaded (even if empty)
                if (! $file->relationLoaded('containers')) {
                    $file->load('containers');
                }

                $containers = $file->containers->map(function (Container $container) {
                    return [
                        'id' => $container->id,
                        'type' => $container->type,
                        'source' => $container->source,
                        'source_id' => $container->source_id,
                        'referrer' => $container->referrer,
                        'action_type' => $container->action_type,
                        'blacklisted_at' => $container->blacklisted_at?->toIso8601String(),
                    ];
                })->values()->all();

                $item = [
                    'id' => $file->id,
                    'width' => $width,
                    'height' => $height,
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
                    'will_auto_dislike' => in_array($file->id, $willAutoDislikeIds, true),
                    // Only include minimal metadata (prompt if available) - full metadata loaded on-demand
                    'metadata' => $prompt ? ['prompt' => $prompt] : null,
                    // listing_metadata removed - loaded on-demand in FileDetailsCard when needed
                    'containers' => $containers,
                ];
            }

            $items[] = $item;
            $index++;
        }

        return $items;
    }
}
