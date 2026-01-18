<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $absolutePath = null;
        if ($this->path) {
            $fullPath = storage_path('app/'.$this->path);

            // Normalize the path: use realpath() if file exists (returns canonical absolute path)
            // This handles symlinks, relative paths, and normalizes separators for the OS
            $normalized = realpath($fullPath);
            if ($normalized !== false) {
                $absolutePath = $normalized;
            } else {
                // If file doesn't exist, use the constructed path with OS-native separators
                // storage_path() already uses DIRECTORY_SEPARATOR, so it's OS-appropriate
                $absolutePath = $fullPath;
            }
        }

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

        return [
            'id' => $this->id,
            'source' => $this->source,
            'source_id' => $this->source_id,
            'filename' => $this->filename,
            'ext' => $this->ext,
            'size' => $this->size,
            'mime_type' => $this->mime_type,
            'hash' => $this->hash,
            'title' => $this->title,
            'description' => $this->description,
            'url' => $this->url,
            'file_url' => $fileUrl,
            'referrer_url' => $this->referrer_url,
            'path' => $this->path,
            'absolute_path' => $absolutePath,
            'thumbnail_url' => $this->thumbnail_url,
            'thumbnail_path' => $this->thumbnail_path,
            'disk_url' => $diskUrl,
            'tags' => $this->tags,
            'parent_id' => $this->parent_id,
            'chapter' => $this->chapter,
            'previewed_at' => $this->previewed_at?->toIso8601String(),
            'previewed_count' => $this->previewed_count,
            'seen_at' => $this->seen_at?->toIso8601String(),
            'seen_count' => $this->seen_count,
            'blacklisted_at' => $this->blacklisted_at?->toIso8601String(),
            'blacklist_reason' => $this->blacklist_reason,
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
