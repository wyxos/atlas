<?php

namespace App\Services;

use App\Models\File;
use Illuminate\Support\Facades\Storage;

class DownloadedFileResetService
{
    /**
     * Prepare a downloaded file for a fresh download attempt.
     *
     * This is intentionally destructive: it clears the stored paths and deletes
     * existing assets from disk so DownloadFile will run again.
     */
    public function reset(File $file): void
    {
        if (! $file->downloaded) {
            return;
        }

        $disk = Storage::disk(config('downloads.disk'));

        foreach (['path', 'preview_path', 'poster_path'] as $field) {
            $path = $file->{$field};
            if (! is_string($path) || $path === '') {
                continue;
            }

            try {
                if ($disk->exists($path)) {
                    $disk->delete($path);
                }
            } catch (\Throwable) {
                // Cleanup failures should not prevent a retry.
            }
        }

        $file->forceFill([
            'downloaded' => false,
            'downloaded_at' => null,
            'download_progress' => 0,
            'path' => null,
            'preview_path' => null,
            'poster_path' => null,
            'size' => null,
            'mime_type' => null,
            'ext' => null,
            'not_found' => false,
        ])->save();
    }
}
