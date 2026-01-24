<?php

namespace App\Services\Downloads;

use App\Enums\DownloadTransferStatus;
use App\Events\DownloadTransferProgressUpdated;
use App\Models\DownloadTransfer;
use App\Models\File;

class DownloadTransferProgressBroadcaster
{
    public function maybeBroadcast(int $transferId): void
    {
        $transfer = DownloadTransfer::query()->find($transferId);
        if (! $transfer || ! $transfer->bytes_total) {
            return;
        }

        if (in_array($transfer->status, [DownloadTransferStatus::PAUSED, DownloadTransferStatus::CANCELED], true)) {
            return;
        }

        $percent = (int) floor(($transfer->bytes_downloaded / $transfer->bytes_total) * 100);
        $percent = max(0, min(100, $percent));
        $boundary = intdiv($percent, 5) * 5;

        if ($boundary <= (int) $transfer->last_broadcast_percent) {
            return;
        }

        $updated = DownloadTransfer::query()
            ->whereKey($transfer->id)
            ->where('last_broadcast_percent', '<', $boundary)
            ->update([
                'last_broadcast_percent' => $boundary,
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            return;
        }

        File::query()->whereKey($transfer->file_id)->update([
            'download_progress' => $boundary,
            'updated_at' => now(),
        ]);

        try {
            event(new DownloadTransferProgressUpdated(
                DownloadTransferPayload::forProgress($transfer, $boundary)
            ));
        } catch (\Throwable) {
            // Broadcast errors shouldn't fail downloads.
        }
    }
}
