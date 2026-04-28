<?php

namespace App\Services\Downloads;

use App\Enums\DownloadTransferStatus;
use App\Events\DownloadTransfersRemoved;
use App\Models\DownloadTransfer;

class DownloadTransferActionSupport
{
    public function __construct(private readonly DownloadTransferRemovalService $transferRemovalService) {}

    public function canPause(DownloadTransfer $downloadTransfer): bool
    {
        return in_array($downloadTransfer->status, [
            DownloadTransferStatus::PENDING,
            DownloadTransferStatus::QUEUED,
            DownloadTransferStatus::PREPARING,
            DownloadTransferStatus::DOWNLOADING,
            DownloadTransferStatus::ASSEMBLING,
        ], true);
    }

    public function canCancel(DownloadTransfer $downloadTransfer): bool
    {
        return ! $downloadTransfer->isTerminal()
            && $downloadTransfer->status !== DownloadTransferStatus::CANCELED
            && $downloadTransfer->status !== DownloadTransferStatus::PREVIEWING;
    }

    public function shouldQueueBulkRemoval(int $count): bool
    {
        return $count > $this->transferRemovalService->bulkRemovalSyncLimit();
    }

    public function shouldQueueSingleRemoval(DownloadTransfer $downloadTransfer): bool
    {
        return ! $downloadTransfer->isTerminal()
            && $downloadTransfer->status !== DownloadTransferStatus::CANCELED;
    }

    /**
     * @param  list<int>  $ids
     */
    public function shouldQueueRemovalByIds(array $ids): bool
    {
        return DownloadTransfer::query()
            ->whereIn('id', $ids)
            ->whereNotIn('status', [
                DownloadTransferStatus::COMPLETED,
                DownloadTransferStatus::FAILED,
                DownloadTransferStatus::CANCELED,
            ])
            ->exists();
    }

    /**
     * @param  list<int>  $ids
     */
    public function broadcastRemoved(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        try {
            event(new DownloadTransfersRemoved($ids));
        } catch (\Throwable) {
            // Broadcast errors shouldn't fail removal actions.
        }
    }
}
