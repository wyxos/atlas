<?php

namespace App\Support;

use App\Models\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

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

        $config = config('filesystems.disks.atlas_app', []);
        $visibility = $config['visibility'] ?? 'private';

        if ($visibility === 'public') {
            return route('storage.atlas_app', ['path' => $normalized]);
        }

        return URL::temporarySignedRoute('storage.atlas_app', now()->addMinutes(30), ['path' => $normalized], absolute: false);
    }
}
