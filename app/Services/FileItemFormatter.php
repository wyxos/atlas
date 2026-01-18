<?php

namespace App\Services;

use App\Models\Container;
use App\Models\File;
use Illuminate\Database\Eloquent\Collection;

class FileItemFormatter
{
    /**
     * Format files into items structure for frontend.
     */
    public static function format($files, int|string $page = 1, array $willAutoDislikeIds = []): array
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

            // Get reaction if loaded
            $reaction = null;
            if ($file->relationLoaded('reaction')) {
                $reactionModel = $file->getRelation('reaction');
                if ($reactionModel) {
                    $reaction = [
                        'type' => $reactionModel->type,
                    ];
                }
            }

            $originalUrl = $file->url;
            $thumbnailUrl = $file->preview_url;

            if ($file->downloaded && $file->path) {
                $originalUrl = route('api.files.downloaded', ['file' => $file->id]);
                if (str_starts_with($file->mime_type ?? '', 'video/')) {
                    $thumbnailUrl = $file->poster_path
                        ? route('api.files.poster', ['file' => $file->id])
                        : $originalUrl;
                } else {
                    $thumbnailUrl = $file->preview_path
                        ? route('api.files.preview', ['file' => $file->id])
                        : $originalUrl;
                }
            } else {
                if (! $originalUrl && $file->path) {
                    $originalUrl = route('api.files.serve', ['file' => $file->id]);
                }
                $thumbnailUrl = $thumbnailUrl ?? $originalUrl;
            }

            $item = [
                'id' => $file->id,
                'width' => $width,
                'height' => $height,
                'src' => $thumbnailUrl,
                // Vibe (new) expects preview/original for the internal MasonryLoader.
                // Keep existing src/originalUrl for backward compatibility in the Atlas UI.
                'preview' => $thumbnailUrl,
                'original' => $originalUrl,
                'timeoutSeconds' => 30,
                'originalUrl' => $originalUrl, // Needed for FileViewer to show original images
                'thumbnail' => $thumbnailUrl,
                'type' => str_starts_with($file->mime_type ?? '', 'video/') ? 'video' : 'image',
                'page' => $page,
                'key' => "{$page}-{$file->id}",
                'index' => $index,
                'notFound' => false,
                'previewed_count' => $file->previewed_count ?? 0,
                'seen_count' => $file->seen_count ?? 0,
                'auto_disliked' => $file->auto_disliked ?? false,
                'will_auto_dislike' => in_array($file->id, $willAutoDislikeIds, true),
                'reaction' => $reaction, // Current user's reaction for this file
                // Include metadata with prompt if available - full metadata loaded on-demand
                'metadata' => $prompt ? ['prompt' => $prompt] : null,
                // listing_metadata removed - loaded on-demand in FileDetailsCard when needed
                'containers' => $containers, // Needed for container badges and reactions
            ];

            $items[] = $item;
            $index++;
        }

        return $items;
    }
}
