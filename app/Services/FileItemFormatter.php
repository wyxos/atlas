<?php

namespace App\Services;

use App\Models\Container;
use App\Models\File;
use Illuminate\Database\Eloquent\Collection;

class FileItemFormatter
{
    private static function toRelativeInternalApiUrl(?string $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return $url;
        }

        if (str_starts_with($url, '/')) {
            return $url;
        }

        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if ($host === '' || $path === '' || ! str_starts_with($path, '/')) {
            return $url;
        }

        $requestHost = request()?->getHost();
        if (! is_string($requestHost) || strtolower($requestHost) !== $host) {
            return $url;
        }

        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return $path.$query;
    }

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

            // Extract only width/height for masonry layout.
            // Prefer FileMetadata payload, but fall back to listing_metadata (extension ingest uses this).
            $listing = is_array($file->listing_metadata) ? $file->listing_metadata : [];
            $width = (int) ($metadata['width'] ?? ($listing['width'] ?? 500));
            $height = (int) ($metadata['height'] ?? ($listing['height'] ?? 500));

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
                $originalUrl = route('api.files.downloaded', ['file' => $file->id], false);
                $thumbnailUrl = route('api.files.preview', ['file' => $file->id], false);
            } else {
                if (! $originalUrl && $file->path) {
                    $originalUrl = route('api.files.serve', ['file' => $file->id], false);
                }
            }

            $mime = $file->mime_type ? (string) $file->mime_type : '';
            $isVideo = $mime !== '' && str_starts_with($mime, 'video/');
            $isImage = $mime !== '' && str_starts_with($mime, 'image/');
            $isAudio = $mime !== '' && str_starts_with($mime, 'audio/');

            // Vibe currently only knows how to load "image" and "video" items.
            // For audio/other files, use an icon SVG as the preview image, but keep the real
            // file URL in `original` for the viewer/actions.
            $mediaKind = $isVideo ? 'video' : ($isImage ? 'image' : ($isAudio ? 'audio' : 'file'));
            $vibeType = $isVideo ? 'video' : 'image';

            if ($mediaKind !== 'image' && $mediaKind !== 'video') {
                $thumbnailUrl = route('api.files.icon', ['file' => $file->id], false);
            }

            $originalUrl = self::toRelativeInternalApiUrl($originalUrl);
            $thumbnailUrl = self::toRelativeInternalApiUrl($thumbnailUrl);

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
                'type' => $vibeType,
                'media_kind' => $mediaKind,
                'mime_type' => $file->mime_type,
                'ext' => $file->ext,
                'filename' => $file->filename,
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
