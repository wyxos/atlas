<?php

namespace App\Jobs\Downloads;

use App\Events\DownloadTransfersRemoved;
use App\Services\Downloads\DownloadTransferRemovalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RemoveDownloadTransfers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    /**
     * @param  list<int>|null  $ids
     */
    public function __construct(
        public ?array $ids = null,
        public bool $alsoFromDisk = false,
        public bool $completedOnly = false,
    ) {
        $this->onQueue('downloads');
    }

    public function handle(DownloadTransferRemovalService $removalService): void
    {
        $broadcastRemoved = function (array $removedIds): void {
            if ($removedIds === []) {
                return;
            }

            event(new DownloadTransfersRemoved($removedIds));
        };

        if ($this->completedOnly) {
            $removalService->removeCompleted($this->alsoFromDisk, $broadcastRemoved);

            return;
        }

        if ($this->ids === null || $this->ids === []) {
            return;
        }

        $removalService->removeByIds($this->ids, $this->alsoFromDisk, $broadcastRemoved);
    }
}
