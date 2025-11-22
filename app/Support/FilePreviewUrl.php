<?php

namespace App\Support;

use App\Models\File;
use Illuminate\Support\Facades\Storage;

class FilePreviewUrl
{
    public static function for(File $file): ?string
    {
        $path = $file->thumbnail_path ?: $file->path;
        if (! $path) {
            return null;
        }

        $normalized = ltrim($path, '/');

        // Check if file exists on any available disk
        $exists = false;
        foreach (['atlas_app', 'atlas'] as $diskName) {
            try {
                if (Storage::disk($diskName)->exists($normalized)) {
                    $exists = true;
                    break;
                }
            } catch (\Throwable $e) {
                // Continue to next disk
            }
        }

        if (! $exists) {
            return null;
        }

        // Use file ID route instead of exposing disk path
        return route('files.preview', ['file' => $file->id]);
    }
}
