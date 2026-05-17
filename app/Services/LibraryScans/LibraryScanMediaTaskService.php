<?php

namespace App\Services\LibraryScans;

use App\Enums\LibraryScanItemStatus;
use App\Enums\LibraryScanMediaTask as MediaTask;
use App\Enums\LibraryScanRunMode;
use App\Jobs\LibraryScans\CreateLibraryScanStreamableVideo;
use App\Jobs\LibraryScans\GenerateLibraryScanPreviewAssets;
use App\Jobs\LibraryScans\NormalizeLibraryScanAudio;
use App\Models\LibraryScanItem;
use App\Models\LibraryScanMediaTask as LibraryScanMediaTaskModel;
use App\Models\LibraryScanRun;
use App\Services\Library\LibraryIndexSyncDispatcher;

class LibraryScanMediaTaskService
{
    /**
     * @param  list<string>  $tasks
     */
    public function queueMediaTasks(LibraryScanItem $item, array $tasks, bool $regeneratePreviewAssets, LibraryScanService $scanService): int
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
            $scanService->broadcastItem($item->fresh(['mediaTasks']));
        } elseif ($queuedTasks !== []) {
            $scanService->broadcastItem($item->fresh(['mediaTasks']));
        }

        foreach ($queuedTasks as $task) {
            $this->dispatchMediaTask($task, $regeneratePreviewAssets, $scanService);
        }

        return count($queuedTasks);
    }

    public function deferMediaTask(?LibraryScanMediaTaskModel $task, LibraryScanService $scanService): void
    {
        if (! $task || in_array($task->status, MediaTask::terminal(), true)) {
            return;
        }

        $task->update([
            'status' => MediaTask::STATUS_PENDING,
            'phase' => MediaTask::PHASE_PAUSED,
            'progress' => 0,
        ]);

        $item = $task->item()->with(['run', 'mediaTasks'])->first();
        if ($item) {
            $scanService->broadcastItem($item->fresh(['mediaTasks']));
        }
    }

    public function markMediaTaskProcessing(?LibraryScanMediaTaskModel $task, string $phase, int $progress, LibraryScanService $scanService): void
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
                $scanService->broadcastItem($item->fresh(['mediaTasks']));
            } else {
                $this->refreshItemMediaProgress($item, $scanService);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function markMediaTaskCompleted(?LibraryScanMediaTaskModel $task, array $result, LibraryScanService $scanService): void
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
            $this->completeItemIfMediaTasksDone($item, $scanService);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function markMediaTaskFailed(
        ?LibraryScanMediaTaskModel $task,
        string $code,
        string $message,
        array $context,
        LibraryScanService $scanService,
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
            $this->completeItemIfMediaTasksDone($item, $scanService);
        }
    }

    public function markMediaTaskCanceled(?LibraryScanMediaTaskModel $task, LibraryScanService $scanService): void
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
            $this->completeItemIfMediaTasksDone($item, $scanService);
        }
    }

    public function completeItemIfMediaTasksDone(LibraryScanItem $item, LibraryScanService $scanService): void
    {
        if ($item->isTerminal()) {
            if ($item->status === LibraryScanItemStatus::COMPLETED && $item->file_id) {
                app(LibraryIndexSyncDispatcher::class)->files([(int) $item->file_id]);
            }
            $scanService->broadcastItem($item->fresh(['mediaTasks']));

            return;
        }

        $item->loadMissing(['run', 'file', 'mediaTasks']);
        if ($item->mediaTasks->isEmpty()) {
            return;
        }

        $remaining = $item->mediaTasks
            ->whereNotIn('status', MediaTask::terminal());

        if ($remaining->isNotEmpty()) {
            $this->refreshItemMediaProgress($item, $scanService);

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
                app(LibraryIndexSyncDispatcher::class)->files([(int) $item->file_id]);
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

        $scanService->broadcastItem($item->fresh(['mediaTasks']));
        $scanService->completeIfDone($item->run);
    }

    public function dispatchPausedMediaTasksForRun(LibraryScanRun $run, LibraryScanService $scanService): void
    {
        $regeneratePreviewAssets = $run->mode === LibraryScanRunMode::REPARSE;

        LibraryScanMediaTaskModel::query()
            ->where('status', MediaTask::STATUS_PENDING)
            ->where('phase', MediaTask::PHASE_PAUSED)
            ->whereHas('item', fn ($query) => $query->where('library_scan_run_id', $run->id))
            ->orderBy('id')
            ->chunkById(500, function ($tasks) use ($regeneratePreviewAssets, $scanService): void {
                foreach ($tasks as $task) {
                    $task->update([
                        'phase' => MediaTask::PHASE_QUEUED,
                        'updated_at' => now(),
                    ]);

                    $this->dispatchMediaTask($task, $regeneratePreviewAssets, $scanService);
                }
            });
    }

    private function refreshItemMediaProgress(LibraryScanItem $item, LibraryScanService $scanService): void
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

        $scanService->broadcastItem($item->fresh(['mediaTasks']));
    }

    private function dispatchMediaTask(LibraryScanMediaTaskModel $task, bool $regeneratePreviewAssets, LibraryScanService $scanService): void
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

        $this->markMediaTaskFailed($task, 'unknown_media_task', "Unknown media task [$task->type].", [], $scanService);
    }
}
