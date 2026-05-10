<?php

namespace App\Jobs\LibraryScans;

use App\Enums\LibraryScanItemStatus;
use App\Enums\LibraryScanRunStatus;
use App\Models\LibraryScanItem;
use App\Models\LibraryScanRun;
use App\Services\LibraryScans\LibraryScanService;
use App\Support\AtlasStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;

class ScanLibraryRun implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    private const array IGNORED_DIRECTORIES = [
        '.git',
        '.hg',
        '.svn',
        '__macosx',
        '@eadir',
        'typesense-data',
    ];

    private const array IGNORED_FILES = [
        '.ds_store',
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
            $run = $run->fresh();
            $scans->broadcastRun($run);
            $scans->dispatchPendingImports($run);
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

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', rtrim($path, '\\/'));
        $realPath = realpath($path);

        return str_replace('\\', '/', rtrim($realPath !== false ? $realPath : $path, '/')).'/';
    }
}
