<?php

namespace App\Jobs\LibraryScans;

use App\Enums\LibraryScanItemStatus;
use App\Enums\LibraryScanRunStatus;
use App\Models\File as AtlasFile;
use App\Models\LibraryScanItem;
use App\Models\LibraryScanRun;
use App\Services\LibraryScans\LibraryScanService;
use App\Support\FileMimeType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReparseImportedFilesRun implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    private const int BATCH_SIZE = 500;

    public int $timeout = 1800;

    public function __construct(private readonly int $runId)
    {
        $this->onQueue('library-scans');
    }

    public function handle(LibraryScanService $scans): void
    {
        $run = LibraryScanRun::query()->find($this->runId);
        if (! $run || in_array($run->status, LibraryScanRunStatus::terminal(), true)) {
            return;
        }

        if ($run->status === LibraryScanRunStatus::PAUSED) {
            return;
        }

        $run->update([
            'status' => LibraryScanRunStatus::SCANNING,
            'phase' => 'reparse_queued',
            'started_at' => $run->started_at ?? now(),
            'error' => null,
        ]);
        $scans->broadcastRun($run->fresh());

        try {
            $this->queueImportedFiles($run, $scans);
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

        $run = $run->fresh();
        $scans->dispatchPendingParsers($run, regeneratePreviewAssets: true);
        $scans->completeIfDone($run);
        $scans->broadcastRun($run->fresh());
    }

    private function queueImportedFiles(LibraryScanRun $run, LibraryScanService $scans): void
    {
        AtlasFile::query()
            ->select(['id', 'path', 'filename', 'hash', 'mime_type', 'size'])
            ->whereNotNull('imported_at')
            ->whereNotNull('path')
            ->whereNotExists(function ($query) use ($run): void {
                $query->selectRaw('1')
                    ->from('library_scan_items')
                    ->whereColumn('library_scan_items.file_id', 'files.id')
                    ->where('library_scan_items.library_scan_run_id', $run->id);
            })
            ->chunkById(self::BATCH_SIZE, function ($files) use ($run, $scans): bool {
                $run->refresh();
                if ($run->status === LibraryScanRunStatus::PAUSED || $run->status === LibraryScanRunStatus::CANCELED) {
                    $scans->broadcastRun($run);

                    return false;
                }

                foreach ($files as $file) {
                    $this->queueImportedFile($run, $file, $scans);
                }

                $scans->refreshCounters($run->fresh());
                $scans->broadcastRun($run->fresh());

                return true;
            });
    }

    private function queueImportedFile(LibraryScanRun $run, AtlasFile $file, LibraryScanService $scans): void
    {
        $parser = FileMimeType::category($file->mime_type);
        $isParseable = $parser !== 'other';

        $item = LibraryScanItem::query()->create([
            'library_scan_run_id' => $run->id,
            'file_id' => $file->id,
            'original_path' => $file->path,
            'imported_path' => $file->path,
            'hash' => $file->hash,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
            'status' => $isParseable ? LibraryScanItemStatus::IMPORTED : LibraryScanItemStatus::COMPLETED,
            'phase' => $isParseable ? 'reparse_queued' : 'completed',
            'progress' => $isParseable ? 35 : 100,
            'parser' => $isParseable ? $parser : null,
        ]);

        $scans->broadcastItem($item);
    }
}
