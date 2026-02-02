<?php

namespace App\Jobs\Downloads;

use App\Enums\DownloadTransferStatus;
use App\Models\DownloadTransfer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class QueueDownloadTransfer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $downloadTransferId)
    {
        $this->onQueue('downloads');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $transfer = DownloadTransfer::query()->find($this->downloadTransferId);
        if (! $transfer) {
            return;
        }

        if ($transfer->status !== DownloadTransferStatus::QUEUED) {
            return;
        }

        PrepareDownloadTransfer::dispatch($transfer->id);
    }
}
