<?php

namespace App\Console\Commands;

use App\Jobs\Downloads\RemoveDownloadTransfers;
use App\Services\Downloads\DownloadTransferActionSupport;
use App\Services\Downloads\DownloadTransferRemovalService;
use Illuminate\Console\Command;

class PruneCompletedDownload extends Command
{
    protected $signature = 'atlas:prune-completed-download
        {--dry-run : Count eligible completed download transfers without pruning them}';

    protected $description = 'Remove old completed download rows from the download list';

    public function handle(
        DownloadTransferRemovalService $removalService,
        DownloadTransferActionSupport $actionSupport,
    ): int {
        $retention = max(0, (int) config('downloads.auto_remove_completed_after', 24));

        if ($retention <= 0) {
            $this->info('Automatic completed download pruning is disabled.');

            return self::SUCCESS;
        }

        $cutoff = now()->subHours($retention);
        $count = $removalService->completedCount($cutoff);

        if ($this->option('dry-run')) {
            $this->info("Would prune {$count} completed download transfer(s).");

            return self::SUCCESS;
        }

        if ($count === 0) {
            $this->info('Pruned 0 completed download transfer(s).');

            return self::SUCCESS;
        }

        if ($actionSupport->shouldQueueBulkRemoval($count)) {
            RemoveDownloadTransfers::dispatch(
                completedOnly: true,
                completedBefore: $cutoff->toISOString(),
            );

            $this->info("Queued pruning for {$count} completed download transfer(s).");

            return self::SUCCESS;
        }

        $removedIds = [];
        $removedCount = $removalService->removeCompleted(
            afterChunk: function (array $chunkIds) use (&$removedIds): void {
                $removedIds = [...$removedIds, ...$chunkIds];
            },
            finishedBefore: $cutoff,
        );
        $actionSupport->broadcastRemoved($removedIds);

        $this->info("Pruned {$removedCount} completed download transfer(s).");

        return self::SUCCESS;
    }
}
