<?php

namespace App\Jobs\LibraryScans;

use App\Enums\LibraryScanItemStatus;
use App\Enums\LibraryScanRunStatus;
use App\Models\File as AtlasFile;
use App\Models\LibraryScanItem;
use App\Models\LibraryScanRun;
use App\Services\LibraryScans\LibraryScanService;
use App\Services\Local\LocalBrowseIndexSyncService;
use App\Support\AtlasStorage;
use App\Support\FileMimeType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ScanLibraryRun implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public function __construct(private readonly int $runId)
    {
        $this->onQueue('library-scans');
    }

    public function handle(AtlasStorage $storage, LibraryScanService $scans): void
    {
        $run = LibraryScanRun::query()->find($this->runId);
        if (! $run || in_array($run->status, LibraryScanRunStatus::terminal(), true)) {
            return;
        }

        if ($run->status === LibraryScanRunStatus::PAUSED) {
            return;
        }

        if (! Schema::hasColumn('files', 'imported_at')) {
            $run->update([
                'status' => LibraryScanRunStatus::FAILED,
                'phase' => 'failed',
                'finished_at' => now(),
                'error' => 'files.imported_at is missing. Run php artisan atlas:ensure-files-imported-at-column before scanning.',
            ]);
            $scans->broadcastRun($run->fresh());

            return;
        }

        $storage->ensureManagedDirectories();

        $run->update([
            'status' => LibraryScanRunStatus::SCANNING,
            'phase' => 'scanning',
            'started_at' => $run->started_at ?? now(),
            'error' => null,
        ]);
        $scans->broadcastRun($run->fresh());

        $atlasRoot = $this->normalizePath($storage->rootPath());
        $appRoot = $this->normalizePath($storage->appRootPath());
        if (! is_dir($atlasRoot)) {
            $run->update([
                'status' => LibraryScanRunStatus::FAILED,
                'phase' => 'failed',
                'finished_at' => now(),
                'error' => "Atlas root does not exist: {$atlasRoot}",
            ]);
            $scans->broadcastRun($run->fresh());

            return;
        }

        try {
            $this->scanFiles($run, $atlasRoot, $appRoot, $storage, $scans);
        } catch (\Throwable $e) {
            $run->update([
                'status' => LibraryScanRunStatus::FAILED,
                'phase' => 'failed',
                'finished_at' => now(),
                'error' => $e->getMessage(),
            ]);
            $scans->broadcastRun($run->fresh());

            return;
        }

        $run = $run->fresh();
        if ($run->status === LibraryScanRunStatus::PAUSED || $run->status === LibraryScanRunStatus::CANCELED) {
            return;
        }

        $run->update([
            'status' => LibraryScanRunStatus::PROCESSING,
            'phase' => 'processing',
            'scan_completed_at' => now(),
        ]);

        $scans->dispatchPendingParsers($run->fresh());
        $scans->completeIfDone($run->fresh());
        $scans->broadcastRun($run->fresh());
    }

    private function scanFiles(
        LibraryScanRun $run,
        string $atlasRoot,
        string $appRoot,
        AtlasStorage $appStorage,
        LibraryScanService $scans,
    ): void {
        $directory = new \RecursiveDirectoryIterator($atlasRoot, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator(
            $directory,
            function (\SplFileInfo $current) use ($appRoot): bool {
                if ($current->isLink()) {
                    return false;
                }

                $path = $this->normalizePath($current->getPathname());

                return ! str_starts_with($path, $appRoot);
            },
        );

        $iterator = new \RecursiveIteratorIterator($filter);
        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile() || $file->isLink()) {
                continue;
            }

            $run->refresh();
            if ($run->status === LibraryScanRunStatus::CANCELED) {
                return;
            }

            if ($run->status === LibraryScanRunStatus::PAUSED) {
                $scans->broadcastRun($run);

                return;
            }

            $this->importFile($run, $file->getPathname(), $appStorage, $scans);
        }
    }

    private function importFile(
        LibraryScanRun $run,
        string $absolutePath,
        AtlasStorage $appStorage,
        LibraryScanService $scans,
    ): void {
        $item = LibraryScanItem::query()->create([
            'library_scan_run_id' => $run->id,
            'original_path' => $absolutePath,
            'status' => LibraryScanItemStatus::IMPORTING,
            'phase' => 'hashing',
            'progress' => 5,
        ]);
        $scans->broadcastItem($item);

        try {
            $hash = hash_file('sha256', $absolutePath);
            if (! is_string($hash) || $hash === '') {
                throw new \RuntimeException('Unable to hash file.');
            }

            $size = filesize($absolutePath);
            $size = is_int($size) ? $size : null;
            $mimeType = $this->detectMimeType($absolutePath);
            $extension = pathinfo($absolutePath, PATHINFO_EXTENSION) ?: null;
            $filename = $appStorage->storedFilename(basename($absolutePath), $extension);
            $disk = Storage::disk(AtlasStorage::DISK);
            $importedPath = $appStorage->uniqueSegmentedPath($disk, AtlasStorage::IMPORTS, $filename, $hash);

            $item->update([
                'hash' => $hash,
                'mime_type' => $mimeType,
                'size' => $size,
                'imported_path' => $importedPath,
                'phase' => 'moving',
                'progress' => 25,
            ]);
            $scans->broadcastItem($item->fresh());

            $this->moveToAppStorage($absolutePath, $disk->path($importedPath), $disk, dirname($importedPath));

            $existing = AtlasFile::query()
                ->where('hash', $hash)
                ->orderBy('id')
                ->first();

            if ($existing) {
                $item->update([
                    'file_id' => $existing->id,
                    'status' => LibraryScanItemStatus::COMPLETED,
                    'phase' => 'duplicate',
                    'progress' => 100,
                    'duplicate' => true,
                    'parser' => 'duplicate',
                ]);
                $scans->refreshCounters($run->fresh());
                $scans->broadcastItem($item->fresh());
                $scans->broadcastRun($run->fresh());

                return;
            }

            $file = AtlasFile::query()->create([
                'source' => 'local',
                'source_id' => null,
                'url' => null,
                'referrer_url' => null,
                'path' => $importedPath,
                'filename' => basename($importedPath),
                'ext' => $extension ? strtolower($extension) : null,
                'size' => $size,
                'mime_type' => $mimeType,
                'hash' => $hash,
                'title' => pathinfo($importedPath, PATHINFO_FILENAME),
                'downloaded' => false,
                'downloaded_at' => null,
                'imported_at' => now(),
                'download_progress' => 0,
            ]);

            app(LocalBrowseIndexSyncService::class)->syncFilesByIds([$file->id]);

            $parser = FileMimeType::category($mimeType);
            if ($parser === 'other') {
                $item->update([
                    'file_id' => $file->id,
                    'status' => LibraryScanItemStatus::COMPLETED,
                    'phase' => 'completed',
                    'progress' => 100,
                    'parser' => null,
                ]);
            } else {
                $item->update([
                    'file_id' => $file->id,
                    'status' => LibraryScanItemStatus::IMPORTED,
                    'phase' => 'queued',
                    'progress' => 35,
                    'parser' => $parser,
                ]);
            }

            $scans->refreshCounters($run->fresh());
            $scans->broadcastItem($item->fresh());
            $scans->broadcastRun($run->fresh());
        } catch (\Throwable $e) {
            $scans->markItemFailed($item, 'import_failed', $e->getMessage(), [
                'path' => $absolutePath,
                'exception' => $e::class,
            ]);
        }
    }

    private function moveToAppStorage(string $sourcePath, string $targetPath, $disk, string $directory): void
    {
        if (! $disk->exists($directory)) {
            $disk->makeDirectory($directory, 0755, true);
        }

        if (@rename($sourcePath, $targetPath)) {
            return;
        }

        if (! @copy($sourcePath, $targetPath)) {
            throw new \RuntimeException('Unable to move file into Atlas imports.');
        }

        @unlink($sourcePath);
    }

    private function detectMimeType(string $absolutePath): ?string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }

        $mimeType = finfo_file($finfo, $absolutePath) ?: null;
        finfo_close($finfo);

        return FileMimeType::canonicalize($mimeType);
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', rtrim($path, '\\/'));
        $realPath = realpath($path);

        return str_replace('\\', '/', rtrim($realPath !== false ? $realPath : $path, '/')).'/';
    }
}
