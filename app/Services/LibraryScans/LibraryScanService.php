<?php

namespace App\Services\LibraryScans;

use App\Enums\LibraryScanItemStatus;
use App\Enums\LibraryScanMediaTask as MediaTask;
use App\Enums\LibraryScanRunMode;
use App\Enums\LibraryScanRunStatus;
use App\Events\LibraryScanItemUpdated;
use App\Events\LibraryScanRunUpdated;
use App\Jobs\LibraryScans\CreateLibraryScanStreamableVideo;
use App\Jobs\LibraryScans\GenerateLibraryScanPreviewAssets;
use App\Jobs\LibraryScans\ImportLibraryScanItem;
use App\Jobs\LibraryScans\NormalizeLibraryScanAudio;
use App\Jobs\LibraryScans\ProcessLibraryScanItem;
use App\Jobs\LibraryScans\ReparseImportedFilesRun;
use App\Jobs\LibraryScans\ScanLibraryRun;
use App\Models\LibraryScanItem;
use App\Models\LibraryScanMediaTask as LibraryScanMediaTaskModel;
use App\Models\LibraryScanRun;
use App\Services\Local\LocalBrowseIndexSyncService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class LibraryScanService
{
    public function start(): LibraryScanRun
    {
        $activeRun = LibraryScanRun::query()
            ->whereIn('status', LibraryScanRunStatus::active())
            ->latest()
            ->first();

        if ($activeRun) {
            return $activeRun;
        }

        $run = LibraryScanRun::query()->create([
            'mode' => LibraryScanRunMode::SCAN,
            'status' => LibraryScanRunStatus::PENDING,
            'phase' => 'pending',
        ]);

        $this->broadcastRun($run);
        ScanLibraryRun::dispatch($run->id);

        return $run;
    }

    public function startImportedFileReparse(): LibraryScanRun
    {
        $activeRun = LibraryScanRun::query()
            ->whereIn('status', LibraryScanRunStatus::active())
            ->latest()
            ->first();

        if ($activeRun) {
            return $activeRun;
        }

        $run = LibraryScanRun::query()->create([
            'mode' => LibraryScanRunMode::REPARSE,
            'status' => LibraryScanRunStatus::PENDING,
            'phase' => 'reparse_pending',
        ]);

        $this->broadcastRun($run);
        ReparseImportedFilesRun::dispatch($run->id);

        return $run;
    }

    public function pause(LibraryScanRun $run): LibraryScanRun
    {
        if (! in_array($run->status, [LibraryScanRunStatus::SCANNING, LibraryScanRunStatus::PROCESSING, LibraryScanRunStatus::PENDING], true)) {
            return $run;
        }

        $run->update([
            'status' => LibraryScanRunStatus::PAUSED,
            'phase' => $run->phase ?: 'paused',
            'paused_at' => now(),
        ]);

        $this->broadcastRun($run->fresh());

        return $run->fresh();
    }

    public function resume(LibraryScanRun $run): LibraryScanRun
    {
        if ($run->status !== LibraryScanRunStatus::PAUSED) {
            return $run;
        }

        $shouldImportDiscoveredFiles = $run->mode === LibraryScanRunMode::SCAN
            && $run->scan_completed_at
            && $run->items()->whereIn('status', [LibraryScanItemStatus::PENDING, LibraryScanItemStatus::IMPORTING])->exists();

        $run->update([
            'status' => $run->scan_completed_at ? LibraryScanRunStatus::PROCESSING : LibraryScanRunStatus::SCANNING,
            'phase' => $run->scan_completed_at
                ? 'processing'
                : 'discovering',
            'paused_at' => null,
        ]);

        $run = $run->fresh();
        $this->broadcastRun($run);

        if ($shouldImportDiscoveredFiles) {
            $this->dispatchPendingImports($run);
        } elseif ($run->scan_completed_at) {
            $this->dispatchPendingParsers($run, regeneratePreviewAssets: $run->mode === LibraryScanRunMode::REPARSE);
            $this->completeIfDone($run);
        } else {
            $run->mode === LibraryScanRunMode::REPARSE
                ? ReparseImportedFilesRun::dispatch($run->id)
                : ScanLibraryRun::dispatch($run->id);
        }

        return $run;
    }

    public function cancel(LibraryScanRun $run): LibraryScanRun
    {
        if (in_array($run->status, LibraryScanRunStatus::terminal(), true)) {
            return $run;
        }

        $run->items()
            ->whereNotIn('status', LibraryScanItemStatus::terminal())
            ->update([
                'status' => LibraryScanItemStatus::CANCELED,
                'phase' => 'canceled',
                'progress' => 100,
                'updated_at' => now(),
            ]);

        LibraryScanMediaTaskModel::query()
            ->whereHas('item', fn ($query) => $query->where('library_scan_run_id', $run->id))
            ->whereNotIn('status', MediaTask::terminal())
            ->update([
                'status' => MediaTask::STATUS_CANCELED,
                'phase' => MediaTask::PHASE_CANCELED,
                'progress' => 100,
                'updated_at' => now(),
            ]);

        $run->update([
            'status' => LibraryScanRunStatus::CANCELED,
            'phase' => 'canceled',
            'canceled_at' => now(),
            'finished_at' => now(),
        ]);

        $this->refreshCounters($run->fresh());
        $this->broadcastRun($run->fresh());

        return $run->fresh();
    }

    public function restart(LibraryScanRun $run): LibraryScanRun
    {
        $mode = $run->mode;

        $this->cancel($run);

        return $mode === LibraryScanRunMode::REPARSE
            ? $this->startImportedFileReparse()
            : $this->start();
    }

    public function markItemFailed(
        LibraryScanItem $item,
        string $code,
        string $message,
        array $context = [],
        bool $completeRun = true,
    ): void {
        $item->update([
            'status' => LibraryScanItemStatus::FAILED,
            'phase' => 'failed',
            'progress' => 100,
            'error_code' => $code,
            'error_message' => $message,
            'error_context' => $context,
        ]);

        $this->broadcastItem($item->fresh());
        if ($completeRun) {
            $this->completeIfDone($item->run()->first());
        }
    }

    /**
     * @param  list<string>  $tasks
     */
    public function queueMediaTasks(LibraryScanItem $item, array $tasks, bool $regeneratePreviewAssets = false): int
    {
        $queuedTasks = [];

        foreach (array_unique($tasks) as $type) {
            /** @var LibraryScanMediaTaskModel $task */
            $task = LibraryScanMediaTaskModel::query()->updateOrCreate(
                [
                    'library_scan_item_id' => $item->id,
                    'type' => $type,
                ],
                [
                    'file_id' => $item->file_id,
                    'status' => MediaTask::STATUS_PENDING,
                    'phase' => MediaTask::PHASE_QUEUED,
                    'progress' => 0,
                    'result' => null,
                    'error_code' => null,
                    'error_message' => null,
                    'error_context' => null,
                ],
            );

            $queuedTasks[] = $task;
        }

        if ($queuedTasks !== [] && ! $item->isTerminal()) {
            $item->update([
                'status' => LibraryScanItemStatus::PROCESSING,
                'phase' => MediaTask::PHASE_MEDIA_QUEUED,
                'progress' => 50,
            ]);
            $this->broadcastItem($item->fresh(['mediaTasks']));
        } elseif ($queuedTasks !== []) {
            $this->broadcastItem($item->fresh(['mediaTasks']));
        }

        foreach ($queuedTasks as $task) {
            $this->dispatchMediaTask($task, $regeneratePreviewAssets);
        }

        return count($queuedTasks);
    }

    public function markMediaTaskProcessing(?LibraryScanMediaTaskModel $task, string $phase, int $progress = 0): void
    {
        if (! $task || in_array($task->status, MediaTask::terminal(), true)) {
            return;
        }

        $task->update([
            'status' => MediaTask::STATUS_PROCESSING,
            'phase' => $phase,
            'progress' => max(0, min(99, $progress)),
        ]);

        $item = $task->item()->with(['run', 'mediaTasks'])->first();
        if ($item) {
            if ($item->status === LibraryScanItemStatus::COMPLETED) {
                $this->broadcastItem($item->fresh(['mediaTasks']));
            } else {
                $this->refreshItemMediaProgress($item);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function markMediaTaskCompleted(?LibraryScanMediaTaskModel $task, array $result = []): void
    {
        if (! $task || in_array($task->status, MediaTask::terminal(), true)) {
            return;
        }

        $task->update([
            'status' => MediaTask::STATUS_COMPLETED,
            'phase' => MediaTask::PHASE_COMPLETED,
            'progress' => 100,
            'result' => $result,
            'error_code' => null,
            'error_message' => null,
            'error_context' => null,
        ]);

        $item = $task->item()->with(['run', 'file', 'mediaTasks'])->first();
        if ($item) {
            $this->completeItemIfMediaTasksDone($item);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function markMediaTaskFailed(
        ?LibraryScanMediaTaskModel $task,
        string $code,
        string $message,
        array $context = [],
    ): void {
        if (! $task || in_array($task->status, MediaTask::terminal(), true)) {
            return;
        }

        $task->update([
            'status' => MediaTask::STATUS_FAILED,
            'phase' => MediaTask::PHASE_FAILED,
            'progress' => 100,
            'error_code' => $code,
            'error_message' => $message,
            'error_context' => $context,
        ]);

        $item = $task->item()->with(['run', 'file', 'mediaTasks'])->first();
        if ($item) {
            $this->completeItemIfMediaTasksDone($item);
        }
    }

    public function markMediaTaskCanceled(?LibraryScanMediaTaskModel $task): void
    {
        if (! $task || in_array($task->status, MediaTask::terminal(), true)) {
            return;
        }

        $task->update([
            'status' => MediaTask::STATUS_CANCELED,
            'phase' => MediaTask::PHASE_CANCELED,
            'progress' => 100,
        ]);

        $item = $task->item()->with(['run', 'file', 'mediaTasks'])->first();
        if ($item) {
            $this->completeItemIfMediaTasksDone($item);
        }
    }

    public function completeItemIfMediaTasksDone(LibraryScanItem $item): void
    {
        if ($item->isTerminal()) {
            if ($item->status === LibraryScanItemStatus::COMPLETED && $item->file_id) {
                app(LocalBrowseIndexSyncService::class)->syncFilesByIds([(int) $item->file_id]);
            }
            $this->broadcastItem($item->fresh(['mediaTasks']));

            return;
        }

        $item->loadMissing(['run', 'file', 'mediaTasks']);
        if ($item->mediaTasks->isEmpty()) {
            return;
        }

        $remaining = $item->mediaTasks
            ->whereNotIn('status', MediaTask::terminal());

        if ($remaining->isNotEmpty()) {
            $this->refreshItemMediaProgress($item);

            return;
        }

        $failed = $item->mediaTasks->firstWhere('status', MediaTask::STATUS_FAILED);
        if ($failed) {
            $item->update([
                'status' => LibraryScanItemStatus::FAILED,
                'phase' => LibraryScanItemStatus::FAILED,
                'progress' => 100,
                'error_code' => $failed->error_code ?: 'media_task_failed',
                'error_message' => $failed->error_message ?: 'Media task failed.',
                'error_context' => [
                    'media_task_id' => $failed->id,
                    'media_task_type' => $failed->type,
                    ...($failed->error_context ?? []),
                ],
            ]);
        } elseif ($item->mediaTasks->every(fn (LibraryScanMediaTaskModel $task): bool => $task->status === MediaTask::STATUS_CANCELED)) {
            $item->update([
                'status' => LibraryScanItemStatus::CANCELED,
                'phase' => MediaTask::PHASE_CANCELED,
                'progress' => 100,
            ]);
        } else {
            if ($item->file_id) {
                app(LocalBrowseIndexSyncService::class)->syncFilesByIds([(int) $item->file_id]);
            }

            $item->update([
                'status' => LibraryScanItemStatus::COMPLETED,
                'phase' => LibraryScanItemStatus::COMPLETED,
                'progress' => 100,
                'error_code' => null,
                'error_message' => null,
                'error_context' => null,
            ]);
        }

        $this->broadcastItem($item->fresh(['mediaTasks']));
        $this->completeIfDone($item->run);
    }

    public function completeIfDone(?LibraryScanRun $run): void
    {
        if (! $run || in_array($run->status, [LibraryScanRunStatus::PAUSED, LibraryScanRunStatus::CANCELED], true)) {
            return;
        }

        $run = $this->refreshCounters($run);

        if (! $run->scan_completed_at) {
            return;
        }

        $remaining = $run->items()
            ->whereNotIn('status', LibraryScanItemStatus::terminal())
            ->exists();

        if ($remaining) {
            return;
        }

        $run->update([
            'status' => $run->files_failed > 0 ? LibraryScanRunStatus::FAILED : LibraryScanRunStatus::COMPLETED,
            'phase' => $run->files_failed > 0 ? 'failed' : 'completed',
            'finished_at' => now(),
        ]);

        $this->broadcastRun($run->fresh());
    }

    public function dispatchPendingParsers(LibraryScanRun $run, bool $regeneratePreviewAssets = false): void
    {
        $query = $run->items()
            ->whereNotNull('parser')
            ->whereNull('parser_queued_at')
            ->when(
                $run->mode === LibraryScanRunMode::SCAN,
                fn ($query) => $query->where('status', LibraryScanItemStatus::COMPLETED),
                fn ($query) => $query->where('status', LibraryScanItemStatus::IMPORTED),
            );

        $query->each(function (LibraryScanItem $item) use ($regeneratePreviewAssets): void {
            $this->queueParserForImportedScanItem(
                $item,
                regeneratePreviewAssets: $regeneratePreviewAssets,
            );
        });
    }

    public function queueParserForImportedScanItem(
        LibraryScanItem $item,
        bool $regeneratePreviewAssets = false,
    ): bool {
        $queuedAt = now();
        $updated = LibraryScanItem::query()
            ->whereKey($item->id)
            ->whereIn('status', [LibraryScanItemStatus::COMPLETED, LibraryScanItemStatus::IMPORTED])
            ->whereNotNull('file_id')
            ->whereNotNull('parser')
            ->whereNull('parser_queued_at')
            ->update([
                'parser_queued_at' => $queuedAt,
                'updated_at' => $queuedAt,
            ]);

        if ($updated === 0) {
            return false;
        }

        ProcessLibraryScanItem::dispatch(
            (int) $item->id,
            regeneratePreviewAssets: $regeneratePreviewAssets,
        );

        return true;
    }

    public function dispatchPendingImports(LibraryScanRun $run): void
    {
        if ($run->mode !== LibraryScanRunMode::SCAN) {
            return;
        }

        $run = $this->refreshCounters($run);
        $dispatched = 0;

        $run->items()
            ->select('id')
            ->whereIn('status', [LibraryScanItemStatus::PENDING, LibraryScanItemStatus::IMPORTING])
            ->orderBy('id')
            ->chunkById(500, function ($items) use (&$dispatched): void {
                foreach ($items as $item) {
                    ImportLibraryScanItem::dispatch($item->id);
                    $dispatched++;
                }
            });

        if ($dispatched === 0) {
            $this->completeScanImportIfDone($run->fresh());

            return;
        }

        $this->broadcastRun($run->fresh());
    }

    public function recordScanItemCompleted(
        LibraryScanRun $run,
        bool $imported = false,
        bool $duplicate = false,
    ): LibraryScanRun {
        $updates = [
            'files_processed' => DB::raw('files_processed + 1'),
            'updated_at' => now(),
        ];

        if ($imported) {
            $updates['files_imported'] = DB::raw('files_imported + 1');
        }

        if ($duplicate) {
            $updates['files_duplicate'] = DB::raw('files_duplicate + 1');
        }

        DB::table($run->getTable())
            ->where('id', $run->id)
            ->update($updates);

        return $run->fresh();
    }

    public function completeScanImportIfDone(?LibraryScanRun $run): void
    {
        if (! $run
            || $run->mode !== LibraryScanRunMode::SCAN
            || ! $run->scan_completed_at
            || in_array($run->status, [LibraryScanRunStatus::PAUSED, LibraryScanRunStatus::CANCELED], true)
            || in_array($run->status, LibraryScanRunStatus::terminal(), true)
        ) {
            return;
        }

        if ($this->scanHasPendingImports($run)) {
            return;
        }

        $lock = Cache::lock("library-scan-import-complete:{$run->id}", 300);
        if (! $lock->get()) {
            return;
        }

        try {
            $run = $this->refreshCounters($run->fresh());
            if ($this->scanHasPendingImports($run)) {
                return;
            }

            $this->syncImportedFilesForRun($run);
            $this->dispatchPendingParsers($run);
            $this->completeIfDone($run->fresh());
        } finally {
            $lock->release();
        }
    }

    public function refreshCounters(LibraryScanRun $run): LibraryScanRun
    {
        $run->update([
            'files_found' => $run->items()->count(),
            'files_imported' => $run->items()->whereNotNull('imported_path')->count(),
            'files_duplicate' => $run->items()->where('duplicate', true)->count(),
            'files_processed' => $run->items()->where('status', LibraryScanItemStatus::COMPLETED)->count(),
            'files_failed' => $run->items()->where('status', LibraryScanItemStatus::FAILED)->count(),
            'files_canceled' => $run->items()->where('status', LibraryScanItemStatus::CANCELED)->count(),
        ]);

        return $run->fresh();
    }

    public function broadcastRun(LibraryScanRun $run): void
    {
        try {
            event(new LibraryScanRunUpdated(LibraryScanPayload::run($run)));
        } catch (\Throwable) {
            // Broadcasting must not affect scan work.
        }
    }

    public function broadcastItem(LibraryScanItem $item): void
    {
        try {
            $item->loadMissing('mediaTasks');
            event(new LibraryScanItemUpdated(LibraryScanPayload::item($item)));
        } catch (\Throwable) {
            // Broadcasting must not affect scan work.
        }
    }

    private function refreshItemMediaProgress(LibraryScanItem $item): void
    {
        if ($item->isTerminal()) {
            return;
        }

        $item->loadMissing('mediaTasks');
        if ($item->mediaTasks->isEmpty()) {
            return;
        }

        $averageProgress = (int) round($item->mediaTasks->avg(fn (LibraryScanMediaTaskModel $task): int => (int) $task->progress));
        $item->update([
            'status' => LibraryScanItemStatus::PROCESSING,
            'phase' => $item->mediaTasks->contains('status', MediaTask::STATUS_PROCESSING)
                ? MediaTask::PHASE_MEDIA_PROCESSING
                : MediaTask::PHASE_MEDIA_QUEUED,
            'progress' => min(99, max(50, 50 + (int) round($averageProgress / 2))),
        ]);

        $this->broadcastItem($item->fresh(['mediaTasks']));
    }

    private function dispatchMediaTask(LibraryScanMediaTaskModel $task, bool $regeneratePreviewAssets): void
    {
        if ($task->type === MediaTask::TASK_PREVIEW_ASSETS) {
            GenerateLibraryScanPreviewAssets::dispatch(
                $task->id,
                regeneratePreviewAssets: $regeneratePreviewAssets,
            );

            return;
        }

        if ($task->type === MediaTask::TASK_AUDIO_NORMALIZATION) {
            NormalizeLibraryScanAudio::dispatch($task->id);

            return;
        }

        if ($task->type === MediaTask::TASK_VIDEO_STREAMABLE) {
            CreateLibraryScanStreamableVideo::dispatch($task->id);

            return;
        }

        $this->markMediaTaskFailed($task, 'unknown_media_task', "Unknown media task [$task->type].");
    }

    private function scanHasPendingImports(LibraryScanRun $run): bool
    {
        return $run->items()
            ->whereNotIn('status', LibraryScanItemStatus::terminal())
            ->exists();
    }

    private function syncImportedFilesForRun(LibraryScanRun $run): void
    {
        $run->items()
            ->select(['id', 'file_id'])
            ->where('status', LibraryScanItemStatus::COMPLETED)
            ->whereNotNull('file_id')
            ->orderBy('id')
            ->chunkById(500, function ($items): void {
                $fileIds = $items
                    ->pluck('file_id')
                    ->filter()
                    ->map(fn ($fileId): int => (int) $fileId)
                    ->unique()
                    ->values()
                    ->all();

                app(LocalBrowseIndexSyncService::class)->syncFilesByIds($fileIds);
            });
    }
}
