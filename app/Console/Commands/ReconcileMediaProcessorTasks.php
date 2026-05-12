<?php

namespace App\Console\Commands;

use App\Enums\MediaProcessorTaskStatus;
use App\Models\MediaProcessorTask;
use App\Services\MediaProcessing\MediaProcessorTaskEventRecorder;
use App\Services\MediaProcessing\RemoteMediaProcessorClient;
use Illuminate\Console\Command;

class ReconcileMediaProcessorTasks extends Command
{
    protected $signature = 'atlas:reconcile-media-processor-tasks
        {--limit=50 : Maximum number of stale active tasks to poll}
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
        $staleMinutes = $this->option('stale-minutes');
        $staleMinutes = is_numeric($staleMinutes)
            ? max(1, (int) $staleMinutes)
            : max(1, (int) config('media_processor.stale_task_minutes', 5));
        $cutoff = now()->subMinutes($staleMinutes);

        $query = MediaProcessorTask::query()
            ->whereIn('status', MediaProcessorTaskStatus::active())
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('last_event_at')
                    ->orWhere('last_event_at', '<=', $cutoff);
            })
            ->oldest('last_event_at')
            ->limit($limit);

        if ($this->option('dry-run')) {
            $this->info("Stale active media processor tasks: {$query->count()}");

            return self::SUCCESS;
        }

        $polled = 0;
        $failed = 0;

        /** @var MediaProcessorTask $task */
        foreach ($query->get() as $task) {
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
}
