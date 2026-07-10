<?php

namespace App\Jobs\Downloads;

use App\Enums\DownloadTransferStatus;
use App\Models\DownloadTransfer;
use App\Services\Downloads\DownloadTransferGeneration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class QueueDownloadTransfer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public ?int $attempt = null;

    public function __construct(public int $downloadTransferId, ?int $attempt = null)
    {
        $this->attempt = $attempt;
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
        $this->attempt ??= (int) ($transfer->attempt ?? 0);

        if (! DownloadTransferGeneration::matches($transfer, $this->attempt, [DownloadTransferStatus::QUEUED])) {
            return;
        }

        PrepareDownloadTransfer::dispatch($transfer->id, $this->attempt);
    }
}
