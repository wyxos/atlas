<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
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

        return [
            'id' => $this->id,
            'source' => $this->source,
            'filename' => $this->filename,
            'ext' => $this->ext,
            'size' => $this->size,
            'mime_type' => $this->mime_type,
            'title' => $this->title,
            'url' => $this->url,
            'path' => $this->path,
            'absolute_path' => $absolutePath,
            'thumbnail_url' => $this->thumbnail_url,
            'downloaded' => $this->downloaded,
            'not_found' => $this->not_found,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
