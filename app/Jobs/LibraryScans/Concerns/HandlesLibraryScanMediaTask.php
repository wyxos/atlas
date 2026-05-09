<?php

namespace App\Jobs\LibraryScans\Concerns;

use App\Enums\LibraryScanItemStatus;
use App\Enums\LibraryScanMediaTask as MediaTask;
use App\Enums\LibraryScanRunStatus;
use App\Models\LibraryScanMediaTask;
use App\Services\LibraryScans\LibraryScanService;

trait HandlesLibraryScanMediaTask
{
    private function loadTask(): ?LibraryScanMediaTask
    {
        /** @var LibraryScanMediaTask|null $task */
        $task = LibraryScanMediaTask::query()
            ->with(['item.run', 'file'])
            ->find($this->taskId);

        if (! $task || in_array($task->status, MediaTask::terminal(), true)) {
            return null;
        }

        return $task;
    }

    private function isRunnable(LibraryScanMediaTask $task, LibraryScanService $scans): bool
    {
        $item = $task->item;
        $run = $item?->run;

        if (! $item || ! $run || ! $task->file) {
            $scans->markMediaTaskFailed($task, 'missing_media_task_context', 'Media task context is missing.');

            return false;
        }

        if ($run->status === LibraryScanRunStatus::CANCELED || $item->status === LibraryScanItemStatus::CANCELED) {
            $scans->markMediaTaskCanceled($task);

            return false;
        }

        if ($item->isTerminal()) {
            return false;
        }

        if ($run->status === LibraryScanRunStatus::PAUSED) {
            $this->release(30);

            return false;
        }

        return true;
    }
}
