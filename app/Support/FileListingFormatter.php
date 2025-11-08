<?php

namespace App\Support;

use App\Models\File;
use Illuminate\Support\Facades\Storage;

class FileListingFormatter
{
    /**
     * @param  array<string, mixed>  $reactions
     * @param  callable(File, string, array&): string|null  $remoteUrlDecorator
     * @param  array<string, mixed>  $serviceCache
     * @return array<string, mixed>|null
     */
    public static function format(?File $file, array $reactions, int $page, ?callable $remoteUrlDecorator = null, array &$serviceCache = []): ?array
    {
        if (! $file) {
            return null;
        }

        $id = (int) $file->getKey();
        $remoteThumbnail = $file->thumbnail_url;
        $mime = (string) ($file->mime_type ?? '');
        $hasPath = (bool) $file->path;
        $original = null;

        if ($hasPath) {
            $original = route('files.view', ['file' => $id]);
        } elseif ($file->url) {
            if ($remoteUrlDecorator) {
                $original = $remoteUrlDecorator($file, (string) $file->url, $serviceCache);
            } else {
                $original = (string) $file->url;
            }
        }

        $localPreview = FilePreviewUrl::for($file);
        $thumbnail = $localPreview ?? $remoteThumbnail;
        $type = str_starts_with($mime, 'video/') ? 'video' : (str_starts_with($mime, 'image/') ? 'image' : (str_starts_with($mime, 'audio/') ? 'audio' : 'other'));

        $detailMetadata = $file->metadata?->payload ?? [];
        if (! is_array($detailMetadata)) {
            $detailMetadata = is_string($detailMetadata) ? json_decode($detailMetadata, true) ?: [] : [];
        }

        $listingMetadata = $file->listing_metadata;
        if (! is_array($listingMetadata)) {
            $listingMetadata = is_string($listingMetadata) ? json_decode($listingMetadata, true) ?: [] : [];
        }

        $width = (int) ($detailMetadata['width'] ?? 0);
        $height = (int) ($detailMetadata['height'] ?? 0);

        if ($width <= 0 && $height > 0) {
            $width = $height;
        }

        if ($height <= 0 && $width > 0) {
            $height = $width;
        }

        if ($width <= 0) {
            $width = 512;
        }

        if ($height <= 0) {
            $height = 512;
        }

        $reactionType = $reactions[$id] ?? null;
        $prompt = $detailMetadata['prompt'] ?? data_get($listingMetadata, 'meta.prompt');
        $moderation = $detailMetadata['moderation'] ?? null;

        // Calculate absolute disk path
        $absolutePath = null;
        if ($hasPath && $file->path) {
            $path = (string) $file->path;
            // Try atlas_app first, then atlas
            foreach (['atlas_app', 'atlas'] as $diskName) {
                $disk = Storage::disk($diskName);
                if ($disk->exists($path)) {
                    try {
                        $absolutePath = $disk->path($path);
                        break;
                    } catch (\Throwable $e) {
                        // Continue to next disk
                    }
                }
            }
        }

        return [
            'id' => $id,
            'preview' => $thumbnail ?? $original,
            'original' => $original,
            'true_original_url' => $file->url ?: null,
            'true_thumbnail_url' => $remoteThumbnail ?: ($localPreview ?? null),
            'referrer_url' => $file->referrer_url ?: null,
            'is_local' => $hasPath,
            'absolute_path' => $absolutePath,
            'type' => $type,
            'width' => $width,
            'height' => $height,
            'page' => $page,
            'containers' => PhotoContainers::forFile($file),
            'metadata' => [
                'prompt' => is_string($prompt) ? $prompt : null,
                'moderation' => is_array($moderation) ? $moderation : null,
            ],
            'listing_metadata' => $listingMetadata,
            'detail_metadata' => $detailMetadata,
            'previewed_count' => (int) $file->previewed_count,
            'seen_count' => (int) $file->seen_count,
            'not_found' => (bool) $file->not_found,
            'loved' => $reactionType === 'love',
            'liked' => $reactionType === 'like',
            'disliked' => $reactionType === 'dislike',
            'funny' => $reactionType === 'funny',
        ];
    }
}

