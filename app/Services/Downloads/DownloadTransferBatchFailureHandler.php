<?php

namespace App\Services\Downloads;

use App\Enums\DownloadChunkStatus;
use App\Enums\DownloadTransferStatus;
use App\Events\DownloadTransferProgressUpdated;
use App\Jobs\Downloads\PumpDomainDownloads;
use App\Models\DownloadChunk;
use App\Models\DownloadTransfer;
use Throwable;

final class DownloadTransferBatchFailureHandler
{
    /**
     * @param  list<int>  $chunkIds
     */
    public function handle(int $transferId, int $attempt, string $domain, array $chunkIds, Throwable $exception): void
    {
        $message = DownloadFailureMessage::normalize($exception->getMessage(), 'Chunk download batch failed.');
        $message = trim(str_replace(["\r", "\n"], ' ', $message));
        $failed = DownloadTransferGeneration::runLocked($transferId, $attempt, [
            DownloadTransferStatus::DOWNLOADING,
            DownloadTransferStatus::ASSEMBLING,
        ], function (DownloadTransfer $transfer) use ($transferId, $chunkIds, $message): void {
            $transfer->forceFill([
                'status' => DownloadTransferStatus::FAILED,
                'failed_at' => now(),
                'error' => $message,
            ])->save();
            DownloadChunk::query()->where('download_transfer_id', $transferId)
                ->whereIn('id', $chunkIds)
                ->whereIn('status', [DownloadChunkStatus::PENDING, DownloadChunkStatus::DOWNLOADING])
                ->update(['status' => DownloadChunkStatus::FAILED, 'failed_at' => now(), 'error' => $message]);
            app(DownloadTransferRuntimeStore::class)->forgetForTransfer($transferId);
        });
        if (! $failed) {
            return;
        }

        $transfer = DownloadTransfer::query()->find($transferId);
        if ($transfer) {
            try {
                event(new DownloadTransferProgressUpdated(
                    DownloadTransferPayload::forProgress($transfer, (int) ($transfer->last_broadcast_percent ?? 0))
                ));
            } catch (Throwable) {
                // Broadcast errors shouldn't fail downloads.
            }
        }

        PumpDomainDownloads::dispatch($domain);
    }
}
