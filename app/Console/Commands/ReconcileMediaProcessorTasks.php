<?php

namespace App\Console\Commands;

use App\Enums\MediaProcessorTaskStatus;
use App\Models\MediaProcessorTask;
use App\Services\MediaProcessing\MediaProcessorTaskEventRecorder;
use App\Services\MediaProcessing\RemoteMediaProcessorClient;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class ReconcileMediaProcessorTasks extends Command
{
    protected $signature = 'atlas:reconcile-media-processor-tasks
        {--limit=50 : Maximum number of stale active tasks to poll}
        {--pending-limit=5 : Maximum stale submitting or queued tasks to poll after in-flight tasks}
        {--stale-minutes= : Minutes without an event before polling}
        {--dry-run : Show task counts without polling the processor}';

    protected $description = 'Poll stale remote media processor tasks and apply their latest status.';

    public function handle(RemoteMediaProcessorClient $client, MediaProcessorTaskEventRecorder $events): int
    {
        if (! $client->enabled()) {
            $this->warn('Remote media processor is disabled or incomplete.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $pendingLimit = max(0, (int) $this->option('pending-limit'));
        $staleMinutes = $this->option('stale-minutes');
        $staleMinutes = is_numeric($staleMinutes)
            ? max(1, (int) $staleMinutes)
            : max(1, (int) config('media_processor.stale_task_minutes', 5));
        $cutoff = now()->subMinutes($staleMinutes);

        $inFlightQuery = $this->staleTasksQuery([
            MediaProcessorTaskStatus::ACCEPTED,
            MediaProcessorTaskStatus::PROCESSING,
        ], $cutoff);
        $pendingQuery = $this->staleTasksQuery([
            MediaProcessorTaskStatus::SUBMITTING,
            MediaProcessorTaskStatus::QUEUED,
        ], $cutoff);

        if ($this->option('dry-run')) {
            $this->info("Stale in-flight media processor tasks: {$inFlightQuery->count()}");
            $this->info("Stale pending media processor tasks: {$pendingQuery->count()}");

            return self::SUCCESS;
        }

        $polled = 0;
        $failed = 0;
        $tasks = $inFlightQuery->limit($limit)->get();
        $pendingBatchLimit = min($pendingLimit, max(0, $limit - $tasks->count()));

        if ($pendingBatchLimit > 0) {
            $tasks = $tasks->concat($pendingQuery->limit($pendingBatchLimit)->get());
        }

        /** @var MediaProcessorTask $task */
        foreach ($tasks as $task) {
            try {
                $events->record($task, $client->fetch($task));
                $polled++;
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("Failed to reconcile task {$task->id}: {$e->getMessage()}");
            }
        }

        $this->info("Polled {$polled} stale media processor task(s); {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<string>  $statuses
     * @return Builder<MediaProcessorTask>
     */
    private function staleTasksQuery(array $statuses, mixed $cutoff): Builder
    {
        return MediaProcessorTask::query()
            ->whereIn('status', $statuses)
            ->where(function (Builder $query) use ($cutoff): void {
                $query->whereNull('last_event_at')
                    ->orWhere('last_event_at', '<=', $cutoff);
            })
            ->oldest('last_event_at');
    }
}
