<?php

namespace App\Jobs\Downloads;

use App\Enums\DownloadTransferStatus;
use App\Events\DownloadTransferProgressUpdated;
use App\Models\DownloadTransfer;
use App\Services\Downloads\DownloadTransferPayload;
use App\Services\Downloads\FileDownloadFinalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateTransferPreview implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    // ffmpeg-based preview generation can exceed the default queue:work timeout (60s).
    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(public int $transferId)
    {
        $this->onQueue('processing');
    }

    public function uniqueId(): string
    {
        return (string) $this->transferId;
    }

    public function handle(FileDownloadFinalizer $finalizer): void
    {
        $transfer = DownloadTransfer::query()->with('file')->find($this->transferId);
        if (! $transfer || ! $transfer->file) {
            return;
        }

        if ($transfer->status !== DownloadTransferStatus::PREVIEWING) {
            return;
        }

        try {
            $updates = $finalizer->generatePreviewAssets($transfer->file);
            if ($updates !== []) {
                $transfer->file->update($updates);
            }
        } catch (\Throwable) {
            // Preview generation is best-effort. Transfers should still complete.
        }

        $transfer->update([
            'status' => DownloadTransferStatus::COMPLETED,
            'finished_at' => now(),
        ]);

        DownloadTransfer::query()->whereKey($transfer->id)->update([
            'last_broadcast_percent' => 100,
            'updated_at' => now(),
        ]);

        $transfer->refresh();

        try {
            event(new DownloadTransferProgressUpdated(
                DownloadTransferPayload::forProgress($transfer, 100)
            ));
        } catch (\Throwable) {
            // Broadcast errors shouldn't fail downloads.
        }
    }
}
