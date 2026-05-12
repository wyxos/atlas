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

class CreateLibraryScanStreamableVideo implements ShouldQueue
{
    use HandlesLibraryScanMediaTask;
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 21600;

    public function __construct(public readonly int $taskId)
    {
        $this->onQueue(MediaTask::CONVERSION_QUEUE);
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

        $scans->markMediaTaskProcessing($task, 'creating_streamable_video', 10);

        try {
            $result = $processor->createStreamableVideo($task->file, $task);
            if (isset($result['remote_task_id'])) {
                $scans->markMediaTaskProcessing($task->fresh() ?? $task, 'remote_video_queued', 15);

                return;
            }

            $scans->markMediaTaskCompleted($task->fresh() ?? $task, $result);
        } catch (\Throwable $e) {
            $scans->markMediaTaskFailed($task->fresh() ?? $task, 'video_streamable_failed', $e->getMessage(), [
                'exception' => $e::class,
            ]);
        }
    }
}
