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
            'thumbnail_url' => $this->thumbnail_url,
            'downloaded' => $this->downloaded,
            'not_found' => $this->not_found,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
