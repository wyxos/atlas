<?php

namespace App\Services\LibraryScans;

use App\Enums\LibraryScanItemStatus;
use App\Models\File as AtlasFile;
use App\Models\LibraryScanItem;
use App\Models\LibraryScanRun;
use App\Support\AtlasStorage;
use App\Support\FileMimeType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class LibraryScanItemImporter
{
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

    public function import(
        LibraryScanItem $item,
        LibraryScanRun $run,
        AtlasStorage $appStorage,
        LibraryScanService $scans,
    ): void {
        $atlasRoot = $this->normalizePath($appStorage->rootPath());
        $sourceParent = dirname((string) $item->original_path);
        $hadImportedPath = is_string($item->imported_path) && $item->imported_path !== '';

        $item->update([
            'status' => LibraryScanItemStatus::IMPORTING,
            'phase' => 'hashing',
            'progress' => 5,
        ]);
        $scans->broadcastItem($item->fresh());

        try {
            $disk = Storage::disk(AtlasStorage::DISK);
            $absolutePath = $this->sourcePathForItem($item, $disk);
            $hash = $this->hashFile($absolutePath);
            $size = filesize($absolutePath);
            $size = is_int($size) ? $size : null;
            $mimeType = $this->detectMimeType($absolutePath);
            $extension = pathinfo($absolutePath, PATHINFO_EXTENSION) ?: null;
            $importedPath = $hadImportedPath
                ? (string) $item->imported_path
                : $appStorage->uniqueSegmentedPath(
                    $disk,
                    AtlasStorage::IMPORTS,
                    $appStorage->randomStoredFilename($extension),
                    $hash,
                );
            $originalRelativePath = $this->relativeAtlasPath((string) $item->original_path, $atlasRoot);
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

            $targetPath = $disk->path($importedPath);
            if (! $this->samePath($absolutePath, $targetPath)) {
                $this->moveToAppStorage($absolutePath, $targetPath, $disk, dirname($importedPath), $hash);
            }

            Cache::lock("library-scan-import-hash:{$hash}", 1800)->block(60, function () use (
                $item,
                $run,
                $scans,
                $existingByOriginalPath,
                $importedPath,
                $extension,
                $size,
                $mimeType,
                $hash,
                $hadImportedPath,
            ): void {
                if ($existingByOriginalPath) {
                    $file = $this->reconcileExistingLocalFile(
                        $existingByOriginalPath,
                        $importedPath,
                        $extension,
                        $size,
                        $mimeType,
                        $hash,
                    );

                    $this->markImportedItem($item, $file, $mimeType);
                    $scans->recordScanItemCompleted($run, imported: ! $hadImportedPath);
                    $scans->broadcastItem($item->fresh());
                    $scans->broadcastRun($run->fresh());

                    return;
                }

                $scanDuplicate = $this->existingScanItemByHash($item, $run, $hash);
                if ($scanDuplicate) {
                    $this->markDuplicateItem($item, (int) $scanDuplicate->file_id);
                    $scans->recordScanItemCompleted(
                        $run,
                        imported: ! $hadImportedPath,
                        duplicate: true,
                    );
                    $scans->broadcastItem($item->fresh());
                    $scans->broadcastRun($run->fresh());

                    return;
                }

                if ($this->filesHashIndexExists()) {
                    $existing = AtlasFile::query()
                        ->where('hash', $hash)
                        ->orderBy('id')
                        ->first();

                    if ($existing) {
                        $this->markDuplicateItem($item, (int) $existing->id);
                        $scans->recordScanItemCompleted(
                            $run,
                            imported: ! $hadImportedPath,
                            duplicate: true,
                        );
                        $scans->broadcastItem($item->fresh());
                        $scans->broadcastRun($run->fresh());

                        return;
                    }
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

                $this->markImportedItem($item, $file, $mimeType);
                $scans->recordScanItemCompleted($run, imported: ! $hadImportedPath);
                $scans->broadcastItem($item->fresh());
                $scans->broadcastRun($run->fresh());
            });

            $this->deleteEmptyDirectParent($sourceParent, $atlasRoot);
        } catch (\Throwable $e) {
            $scans->markItemFailed($item, 'import_failed', $e->getMessage(), [
                'path' => $item->original_path,
                'exception' => $e::class,
            ], completeRun: false);
        }
    }

    private function existingScanItemByHash(LibraryScanItem $item, LibraryScanRun $run, string $hash): ?LibraryScanItem
    {
        return LibraryScanItem::query()
            ->where('library_scan_run_id', $run->id)
            ->where('id', '<', $item->id)
            ->where('hash', $hash)
            ->where('status', LibraryScanItemStatus::COMPLETED)
            ->whereNotNull('file_id')
            ->orderBy('id')
            ->first();
    }

    private function markDuplicateItem(LibraryScanItem $item, int $fileId): void
    {
        $item->update([
            'file_id' => $fileId,
            'status' => LibraryScanItemStatus::COMPLETED,
            'phase' => 'duplicate',
            'progress' => 100,
            'duplicate' => true,
            'parser' => null,
        ]);
    }

    private function filesHashIndexExists(): bool
    {
        return Cache::remember('library-scans:files-hash-index-exists', 600, function (): bool {
            $driver = DB::connection()->getDriverName();
            if (! in_array($driver, ['mysql', 'mariadb'], true)) {
                return true;
            }

            return DB::select('SHOW INDEX FROM `files` WHERE Key_name = ?', ['files_hash_index']) !== [];
        });
    }

    private function sourcePathForItem(LibraryScanItem $item, $disk): string
    {
        $originalPath = (string) $item->original_path;
        if ($originalPath !== '' && is_file($originalPath)) {
            return $originalPath;
        }

        if (is_string($item->imported_path) && $item->imported_path !== '') {
            $importedPath = $disk->path($item->imported_path);
            if (is_file($importedPath)) {
                return $importedPath;
            }
        }

        throw new \RuntimeException('Library scan source file is missing.');
    }

    private function hashFile(string $absolutePath): string
    {
        $hash = hash_file('sha256', $absolutePath);
        if (! is_string($hash) || $hash === '') {
            throw new \RuntimeException('Unable to hash file.');
        }

        return $hash;
    }

    private function moveToAppStorage(string $sourcePath, string $targetPath, $disk, string $directory, string $hash): void
    {
        if (! $disk->exists($directory)) {
            $disk->makeDirectory($directory, 0755, true);
        }

        if (is_file($targetPath)) {
            if ($this->hashFile($targetPath) === $hash) {
                @unlink($sourcePath);

                return;
            }

            throw new \RuntimeException('Atlas import target already exists with different content.');
        }

        if (@rename($sourcePath, $targetPath)) {
            return;
        }

        if (! @copy($sourcePath, $targetPath)) {
            throw new \RuntimeException('Unable to move file into Atlas imports.');
        }

        @unlink($sourcePath);
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

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', rtrim($path, '\\/'));
        $realPath = realpath($path);

        return str_replace('\\', '/', rtrim($realPath !== false ? $realPath : $path, '/')).'/';
    }

    private function normalizeFilePath(string $path): string
    {
        $path = str_replace('\\', '/', rtrim($path, '\\/'));
        $realPath = realpath($path);

        return str_replace('\\', '/', rtrim($realPath !== false ? $realPath : $path, '/'));
    }

    private function samePath(string $first, string $second): bool
    {
        return $this->normalizeFilePath($first) === $this->normalizeFilePath($second);
    }
}
