<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class FileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $downloadsDisk = Storage::disk(config('downloads.disk'));

        $resolveAbsolutePath = function (?string $path) use ($downloadsDisk): ?string {
            if (! $path) {
                return null;
            }

            $fullPath = $downloadsDisk->path($path);

            // Normalize the path: use realpath() if file exists (returns canonical absolute path)
            // This handles symlinks, relative paths, and normalizes separators for the OS
            $normalized = realpath($fullPath);
            if ($normalized !== false) {
                return $normalized;
            }

            // If file doesn't exist, use the constructed path with OS-native separators
            // storage_path() already uses DIRECTORY_SEPARATOR, so it's OS-appropriate
            return $fullPath;
        };

        $absolutePath = $resolveAbsolutePath($this->path);
        $absolutePreviewPath = $resolveAbsolutePath($this->preview_path);

        $payload = is_array($this->metadata?->payload)
            ? $this->metadata->payload
            : (is_string($this->metadata?->payload) ? json_decode($this->metadata->payload, true) : []);
        $listingMetadata = is_array($this->listing_metadata) ? $this->listing_metadata : [];
        $detailMetadata = is_array($this->detail_metadata) ? $this->detail_metadata : [];

        $normalizeDimension = function (mixed $value): ?int {
            if (! is_numeric($value)) {
                return null;
            }

            $int = (int) $value;

            return $int > 0 ? $int : null;
        };

        $width = $normalizeDimension(
            data_get($payload, 'width')
            ?? data_get($detailMetadata, 'width')
            ?? data_get($listingMetadata, 'width')
        );
        $height = $normalizeDimension(
            data_get($payload, 'height')
            ?? data_get($detailMetadata, 'height')
            ?? data_get($listingMetadata, 'height')
        );

        $fileUrl = null;
        if ($this->url) {
            $fileUrl = $this->url;
        } elseif ($this->path) {
            // Generate URL for local files
            $fileUrl = route('api.files.serve', ['file' => $this->id]);
        }

        $diskUrl = null;
        if ($this->downloaded && $this->path) {
            $diskUrl = route('api.files.downloaded', ['file' => $this->id]);
        }
        $previewFileUrl = $this->preview_path ? route('api.files.preview', ['file' => $this->id]) : null;
        $posterUrl = $this->poster_path ? route('api.files.poster', ['file' => $this->id]) : null;

        $blacklistType = null;
        $blacklistRule = null;
        if ($this->blacklisted_at !== null) {
            $hasReason = is_string($this->blacklist_reason) && trim($this->blacklist_reason) !== '';
            $blacklistType = $hasReason ? 'manual' : 'auto';

            if (! $hasReason) {
                try {
                    if ($this->resource->relationLoaded('autoBlacklistModerationAction')) {
                        $hit = $this->resource->getRelation('autoBlacklistModerationAction');
                        if ($hit) {
                            $blacklistRule = [
                                'id' => (int) ($hit->moderation_rule_id ?? 0),
                                'name' => (string) ($hit->moderation_rule_name ?? ''),
                            ];
                        }
                    }
                } catch (\Throwable $e) {
                    // Omit persisted details if relation isn't available or anything fails.
                }

                if ($blacklistRule && $blacklistRule['id'] <= 0 && $blacklistRule['name'] === '') {
                    $blacklistRule = null;
                }
            }
        }

        $autoDislikeRule = null;
        try {
            if ($this->resource->relationLoaded('autoDislikeModerationAction')) {
                $hit = $this->resource->getRelation('autoDislikeModerationAction');
                if ($hit) {
                    $autoDislikeRule = [
                        'id' => (int) ($hit->moderation_rule_id ?? 0),
                        'name' => (string) ($hit->moderation_rule_name ?? ''),
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Omit persisted details if relation isn't available or anything fails.
        }

        return [
            'id' => $this->id,
            'source' => $this->source,
            'source_id' => $this->source_id,
            'filename' => $this->filename,
            'ext' => $this->ext,
            'size' => $this->size,
            'width' => $width,
            'height' => $height,
            'mime_type' => $this->mime_type,
            'hash' => $this->hash,
            'title' => $this->title,
            'description' => $this->description,
            'url' => $this->url,
            'file_url' => $fileUrl,
            'referrer_url' => $this->referrer_url,
            'path' => $this->path,
            'absolute_path' => $absolutePath,
            'absolute_preview_path' => $absolutePreviewPath,
            'preview_url' => $this->preview_url,
            'disk_url' => $diskUrl,
            'preview_file_url' => $previewFileUrl,
            'poster_url' => $posterUrl,
            'preview_path' => $this->preview_path,
            'poster_path' => $this->poster_path,
            'tags' => $this->tags,
            'parent_id' => $this->parent_id,
            'chapter' => $this->chapter,
            'previewed_at' => $this->previewed_at?->toIso8601String(),
            'previewed_count' => $this->previewed_count,
            'seen_at' => $this->seen_at?->toIso8601String(),
            'seen_count' => $this->seen_count,
            'auto_disliked' => (bool) ($this->auto_disliked ?? false),
            'auto_dislike_rule' => $autoDislikeRule && ($autoDislikeRule['id'] > 0 || $autoDislikeRule['name'] !== '') ? $autoDislikeRule : null,
            'blacklisted_at' => $this->blacklisted_at?->toIso8601String(),
            'blacklist_reason' => $this->blacklist_reason,
            'blacklist_type' => $blacklistType,
            'blacklist_rule' => $blacklistRule,
            'downloaded' => $this->downloaded,
            'downloaded_at' => $this->downloaded_at?->toIso8601String(),
            'download_progress' => $this->download_progress,
            'not_found' => $this->not_found,
            'listing_metadata' => $this->listing_metadata,
            'detail_metadata' => $this->detail_metadata,
            'metadata' => $this->metadata ? ['payload' => $this->metadata->payload] : null,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
