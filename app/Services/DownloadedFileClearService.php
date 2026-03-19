<?php

namespace App\Services;

use App\Models\File;
use Illuminate\Support\Facades\Storage;

class DownloadedFileClearService
{
    public function __construct(
        private MetricsService $metricsService,
    ) {}

    public function hasStoredAssets(File $file): bool
    {
        return (bool) $file->downloaded || $this->storedPaths($file) !== [];
    }

    public function clear(File $file, bool $syncSearch = true): bool
    {
        if (! $this->hasStoredAssets($file)) {
            return false;
        }

        $disk = Storage::disk(config('downloads.disk'));

        foreach ($this->storedPaths($file) as $path) {
            try {
                if ($disk->exists($path)) {
                    $disk->delete($path);
                }
            } catch (\Throwable) {
                // Cleanup failures should not prevent state repair.
            }
        }

        $wasDownloaded = (bool) $file->downloaded;
        $this->metricsService->applyDownloadClear($file, $wasDownloaded);

        $file->forceFill([
            'path' => null,
            'preview_path' => null,
            'poster_path' => null,
            'downloaded' => false,
            'downloaded_at' => null,
            'download_progress' => 0,
            'updated_at' => now(),
        ])->save();

        if ($syncSearch) {
            $file->searchable();
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function storedPaths(File $file): array
    {
        return array_values(array_unique(array_filter([
            $file->path,
            $file->preview_path,
            $file->poster_path,
        ], static fn (mixed $path): bool => is_string($path) && $path !== '')));
    }
}
