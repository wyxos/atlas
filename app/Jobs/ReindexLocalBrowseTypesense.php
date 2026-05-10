<?php

namespace App\Jobs;

use App\Models\LocalBrowseReindexRun;
use App\Services\Local\LocalBrowseReindexService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ReindexLocalBrowseTypesense implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;

    public int $tries = 1;

    public function __construct(public readonly int $runId)
    {
        $this->onQueue((string) config('local_browse.typesense.reindex_queue', 'local-browse-reindex'));
        $this->timeout = (int) config('local_browse.typesense.reindex_timeout', 21600);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('local-browse-reindex'))
                ->expireAfter($this->timeout + 300),
        ];
    }

    public function handle(LocalBrowseReindexService $reindex): void
    {
        $run = LocalBrowseReindexRun::query()->find($this->runId);
        if (! $run || $run->isTerminal()) {
            return;
        }

        $reindex->run($run);
    }
}
