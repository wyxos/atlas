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

        if (! Storage::disk('atlas_app')->exists($normalized)) {
            return null;
        }

        return route('storage.atlas_app', ['path' => $normalized]);
    }
}
