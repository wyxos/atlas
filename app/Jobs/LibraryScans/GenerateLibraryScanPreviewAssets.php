<?php

namespace App\Jobs\LibraryScans;

use App\Enums\LibraryScanMediaTask as MediaTask;
use App\Jobs\LibraryScans\Concerns\HandlesLibraryScanMediaTask;
use App\Services\LibraryScans\LibraryScanMediaProcessor;
use App\Services\LibraryScans\LibraryScanService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateLibraryScanPreviewAssets implements ShouldQueue
{
    use HandlesLibraryScanMediaTask;
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(
        public readonly int $taskId,
        public readonly bool $regeneratePreviewAssets = false,
    ) {
        $this->onQueue(MediaTask::PREVIEW_QUEUE);
    }

    public function handle(LibraryScanMediaProcessor $processor, LibraryScanService $scans): void
    {
        $task = $this->loadTask();
        if (! $task) {
            return;
        }

        if (! $this->isRunnable($task, $scans)) {
            return;
        }

        $scans->markMediaTaskProcessing($task, 'previewing', 10);

        try {
            $scans->markMediaTaskCompleted(
                $task->fresh() ?? $task,
                $processor->generatePreviewAssets($task->file, $this->regeneratePreviewAssets),
            );
        } catch (\Throwable $e) {
            $scans->markMediaTaskFailed($task->fresh() ?? $task, 'preview_failed', $e->getMessage(), [
                'exception' => $e::class,
            ]);
        }
    }
}
