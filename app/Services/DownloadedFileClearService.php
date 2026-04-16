<?php

namespace App\Services;

use App\Jobs\DeleteAutoDislikedFileJob;
use App\Models\File;
use App\Services\Local\LocalBrowseIndexSyncService;
use App\Support\AtlasPathResolver;
use Illuminate\Support\Facades\Storage;

class DownloadedFileClearService
{
    private const int DELETE_JOB_CHUNK_SIZE = 200;

    public function __construct(
        private MetricsService $metricsService,
    ) {}

    public function hasStoredAssets(File $file): bool
    {
        return (bool) $file->downloaded || $this->storedPaths($file) !== [];
    }

    public function clear(File $file): bool
    {
        return $this->clearMany([$file]) !== [];
    }

    /**
     * @param  iterable<int, File>  $files
     * @return array<int>
     */
    public function clearMany(iterable $files, bool $queueDelete = false): array
    {
        $files = collect($files)
            ->filter(fn (mixed $file): bool => $file instanceof File)
            ->values();

        if ($files->isEmpty()) {
            return [];
        }

        $clearableFiles = $files
            ->filter(fn (File $file): bool => $this->hasStoredAssets($file))
            ->values();

        if ($clearableFiles->isEmpty()) {
            return [];
        }

        $fileIds = $clearableFiles
            ->pluck('id')
            ->map(fn (mixed $fileId): int => (int) $fileId)
            ->unique()
            ->values()
            ->all();
        $paths = $clearableFiles
            ->flatMap(fn (File $file): array => $this->storedPaths($file))
            ->unique()
            ->values()
            ->all();

        foreach ($clearableFiles as $file) {
            $this->metricsService->applyDownloadClear($file, (bool) $file->downloaded);
        }

        $this->clearStateByIds($fileIds);
        app(LocalBrowseIndexSyncService::class)->syncFilesByIds($fileIds);

        if ($queueDelete) {
            $this->dispatchDeleteJobs($paths);
        } else {
            $this->deletePaths($paths);
        }

        return $fileIds;
    }

    /**
     * @return array<int, string>
     */
    public function storedPaths(File $file): array
    {
        return array_values(array_unique(array_filter([
            $file->path,
            $file->preview_path,
            $file->poster_path,
        ], static fn (mixed $path): bool => is_string($path) && $path !== '')));
    }

    /**
     * @param  array<int, string>  $paths
     */
    private function deletePaths(array $paths): void
    {
        if ($paths === []) {
            return;
        }

        foreach (AtlasPathResolver::preferredDiskNames() as $diskName) {
            foreach ($paths as $path) {
                try {
                    $disk = Storage::disk($diskName);
                    if ($disk->exists($path)) {
                        $disk->delete($path);
                    }
                } catch (\Throwable) {
                    // Cleanup failures should not prevent state repair.
                }
            }
        }
    }

    /**
     * @param  array<int, string>  $paths
     */
    private function dispatchDeleteJobs(array $paths): void
    {
        if ($paths === []) {
            return;
        }

        foreach (array_chunk($paths, self::DELETE_JOB_CHUNK_SIZE) as $chunk) {
            DeleteAutoDislikedFileJob::dispatch(count($chunk) === 1 ? $chunk[0] : $chunk);
        }
    }

    /**
     * @param  array<int>  $fileIds
     */
    private function clearStateByIds(array $fileIds): void
    {
        if ($fileIds === []) {
            return;
        }

        File::query()
            ->whereIn('id', $fileIds)
            ->update([
                'path' => null,
                'preview_path' => null,
                'poster_path' => null,
                'downloaded' => false,
                'downloaded_at' => null,
                'download_progress' => 0,
                'updated_at' => now(),
            ]);
    }
}
