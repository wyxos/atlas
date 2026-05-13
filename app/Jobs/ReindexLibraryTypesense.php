<?php

namespace App\Jobs;

use App\Models\LibraryReindexRun;
use App\Services\Library\LibraryReindexService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ReindexLibraryTypesense implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;

    public int $tries = 1;

    public function __construct(public readonly int $runId)
    {
        $this->onQueue((string) config('library.typesense.reindex_queue', 'library-reindex'));
        $this->timeout = (int) config('library.typesense.reindex_timeout', 21600);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('library-reindex'))
                ->expireAfter($this->timeout + 300),
        ];
    }

    public function handle(LibraryReindexService $reindex): void
    {
        $run = LibraryReindexRun::query()->find($this->runId);
        if (! $run || $run->isTerminal()) {
            return;
        }

        $reindex->run($run);
    }
}
