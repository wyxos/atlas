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

    private const array IGNORED_DIRECTORIES = [
        '.git',
        '.hg',
        '.svn',
        '__macosx',
        '@eadir',
    ];

    private const array IGNORED_FILES = [
        '.ds_store',
        'desktop.ini',
        'ehthumbs.db',
        'ehthumbs_vista.db',
        'thumb.db',
        'thumbs.db',
    ];

    private const array REMOVABLE_EMPTY_PARENT_FILES = [
        '.ds_store',
        '.gitignore',
        '.nomedia',
        'desktop.ini',
        'ehthumbs.db',
        'ehthumbs_vista.db',
        'thumb.db',
        'thumbs.db',
    ];

    private const int DISCOVERY_COUNTER_FLUSH_SIZE = 100;

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
            if (! $run->scan_completed_at) {
                $run->update([
                    'status' => LibraryScanRunStatus::SCANNING,
                    'phase' => 'discovering',
                    'started_at' => $run->started_at ?? now(),
                    'error' => null,
                    'files_found' => 0,
                    'files_imported' => 0,
                    'files_duplicate' => 0,
                    'files_processed' => 0,
                    'files_failed' => 0,
                    'files_canceled' => 0,
                ]);
                $scans->broadcastRun($run->fresh());

                $this->discoverFiles($run, $atlasRoot, $appRoot, $scans);
            }

            $run = $run->fresh();
            if ($run->status === LibraryScanRunStatus::PAUSED || $run->status === LibraryScanRunStatus::CANCELED) {
                return;
            }

            $run->update([
                'status' => LibraryScanRunStatus::PROCESSING,
                'phase' => 'processing',
                'scan_completed_at' => $run->scan_completed_at ?? now(),
            ]);
            $scans->broadcastRun($run->fresh());

            $this->importDiscoveredFiles($run->fresh(), $atlasRoot, $storage, $scans);
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
        ]);

        $scans->dispatchPendingParsers($run->fresh());
        $scans->completeIfDone($run->fresh());
        $scans->broadcastRun($run->fresh());
    }

    private function discoverFiles(
        LibraryScanRun $run,
        string $atlasRoot,
        string $appRoot,
        LibraryScanService $scans,
    ): void {
        $run->items()->delete();

        $directory = new \RecursiveDirectoryIterator($atlasRoot, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator(
            $directory,
            function (\SplFileInfo $current) use ($appRoot): bool {
                if ($current->isLink()) {
                    return false;
                }

                if ($current->isDir() && $this->shouldSkipDirectory($current)) {
                    return false;
                }

                if ($current->isFile() && $this->shouldSkipFile($current)) {
                    return false;
                }

                $path = $this->normalizePath($current->getPathname());

                return ! str_starts_with($path, $appRoot);
            },
        );

        $found = 0;
        $iterator = new \RecursiveIteratorIterator($filter);
        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile() || $file->isLink()) {
                continue;
            }

            if ($this->shouldSkipFile($file)) {
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

            LibraryScanItem::query()->create([
                'library_scan_run_id' => $run->id,
                'original_path' => $file->getPathname(),
                'status' => LibraryScanItemStatus::PENDING,
                'phase' => 'discovered',
            ]);

            $found++;
            if ($found % self::DISCOVERY_COUNTER_FLUSH_SIZE === 0) {
                $run->update(['files_found' => $found]);
                $scans->broadcastRun($run->fresh());
            }
        }

        unset($iterator, $filter, $directory);

        $run->update(['files_found' => $found]);
        $scans->broadcastRun($run->fresh());
    }

    private function importDiscoveredFiles(
        LibraryScanRun $run,
        string $atlasRoot,
        AtlasStorage $appStorage,
        LibraryScanService $scans,
    ): void {
        $importedParentDirectories = [];

        foreach ($run->items()->whereIn('status', [LibraryScanItemStatus::PENDING, LibraryScanItemStatus::IMPORTING])->orderBy('id')->cursor() as $item) {
            $run->refresh();
            if ($run->status === LibraryScanRunStatus::CANCELED) {
                return;
            }

            if ($run->status === LibraryScanRunStatus::PAUSED) {
                $scans->broadcastRun($run);

                return;
            }

            $importedParent = $this->importFile($item, $run, $item->original_path, $atlasRoot, $appStorage, $scans);
            if ($importedParent) {
                $importedParentDirectories[] = $importedParent;
            }
        }

        foreach (array_unique($importedParentDirectories) as $directory) {
            $this->deleteEmptyDirectParent($directory, $atlasRoot);
        }
    }

    private function shouldSkipDirectory(\SplFileInfo $directory): bool
    {
        return in_array(strtolower($directory->getFilename()), self::IGNORED_DIRECTORIES, true);
    }

    private function shouldSkipFile(\SplFileInfo $file): bool
    {
        $filename = strtolower($file->getFilename());

        return str_starts_with($filename, '.')
            || in_array($filename, self::IGNORED_FILES, true);
    }

    private function importFile(
        LibraryScanItem $item,
        LibraryScanRun $run,
        string $absolutePath,
        string $atlasRoot,
        AtlasStorage $appStorage,
        LibraryScanService $scans,
    ): ?string {
        $sourceParent = dirname($absolutePath);
        $item->update([
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
            $filename = $appStorage->randomStoredFilename($extension);
            $disk = Storage::disk(AtlasStorage::DISK);
            $importedPath = $appStorage->uniqueSegmentedPath($disk, AtlasStorage::IMPORTS, $filename, $hash);
            $originalRelativePath = $this->relativeAtlasPath($absolutePath, $atlasRoot);
            $existingByOriginalPath = $this->existingLocalFileByOriginalPath($originalRelativePath);

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

            if ($existingByOriginalPath) {
                $file = $this->reconcileExistingLocalFile(
                    $existingByOriginalPath,
                    $importedPath,
                    $extension,
                    $size,
                    $mimeType,
                    $hash,
                );

                app(LocalBrowseIndexSyncService::class)->syncFilesByIds([$file->id]);
                $this->markImportedItem($item, $file, $mimeType);
                $scans->refreshCounters($run->fresh());
                $scans->broadcastItem($item->fresh());
                $scans->broadcastRun($run->fresh());

                return $sourceParent;
            }

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

                return $sourceParent;
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

            $this->markImportedItem($item, $file, $mimeType);

            $scans->refreshCounters($run->fresh());
            $scans->broadcastItem($item->fresh());
            $scans->broadcastRun($run->fresh());

            return $sourceParent;
        } catch (\Throwable $e) {
            $scans->markItemFailed($item, 'import_failed', $e->getMessage(), [
                'path' => $absolutePath,
                'exception' => $e::class,
            ]);

            return null;
        }
    }

    private function deleteEmptyDirectParent(string $directory, string $atlasRoot): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $parent = $this->normalizePath($directory);
        $root = $this->normalizePath($atlasRoot);
        if ($parent === $root || ! str_starts_with($parent, $root)) {
            return;
        }

        $entries = scandir($directory);
        if ($entries === false) {
            return;
        }

        foreach (array_diff($entries, ['.', '..']) as $entry) {
            $path = $directory.DIRECTORY_SEPARATOR.$entry;
            if (! is_file($path) || ! in_array(strtolower($entry), self::REMOVABLE_EMPTY_PARENT_FILES, true)) {
                return;
            }

            @unlink($path);
        }

        $entries = scandir($directory);
        if ($entries === false || array_diff($entries, ['.', '..']) !== []) {
            return;
        }

        @rmdir($directory);
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

    private function existingLocalFileByOriginalPath(?string $originalRelativePath): ?AtlasFile
    {
        if ($originalRelativePath === null) {
            return null;
        }

        return AtlasFile::query()
            ->where('source', 'local')
            ->where('path', $originalRelativePath)
            ->orderBy('id')
            ->first();
    }

    private function reconcileExistingLocalFile(
        AtlasFile $file,
        string $importedPath,
        ?string $extension,
        ?int $size,
        ?string $mimeType,
        string $hash,
    ): AtlasFile {
        $file->forceFill([
            'path' => $importedPath,
            'filename' => basename($importedPath),
            'ext' => $extension ? strtolower($extension) : null,
            'size' => $size,
            'mime_type' => $mimeType,
            'hash' => $hash,
            'preview_path' => null,
            'poster_path' => null,
            'downloaded' => false,
            'downloaded_at' => null,
            'imported_at' => $file->imported_at ?? now(),
            'download_progress' => 0,
            'not_found' => false,
        ]);

        if (! is_string($file->title) || trim($file->title) === '') {
            $file->title = pathinfo($importedPath, PATHINFO_FILENAME);
        }

        $file->save();

        return $file;
    }

    private function markImportedItem(
        LibraryScanItem $item,
        AtlasFile $file,
        ?string $mimeType,
    ): void {
        $parser = FileMimeType::category($mimeType);
        if ($parser === 'other') {
            $item->update([
                'file_id' => $file->id,
                'status' => LibraryScanItemStatus::COMPLETED,
                'phase' => 'completed',
                'progress' => 100,
                'parser' => null,
            ]);

            return;
        }

        $item->update([
            'file_id' => $file->id,
            'status' => LibraryScanItemStatus::COMPLETED,
            'phase' => 'imported',
            'progress' => 100,
            'parser' => $parser,
        ]);
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

    private function relativeAtlasPath(string $absolutePath, string $atlasRoot): ?string
    {
        $root = $this->normalizePath($atlasRoot);
        $path = $this->normalizeFilePath($absolutePath);

        if (! str_starts_with($path, $root)) {
            return null;
        }

        $relativePath = ltrim(substr($path, strlen($root)), '/');

        return $relativePath !== '' ? $relativePath : null;
    }

    private function normalizeFilePath(string $path): string
    {
        $path = str_replace('\\', '/', rtrim($path, '\\/'));
        $realPath = realpath($path);

        return str_replace('\\', '/', rtrim($realPath !== false ? $realPath : $path, '/'));
    }
}
