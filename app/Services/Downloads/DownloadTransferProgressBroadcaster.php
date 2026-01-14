<?php

namespace App\Services\Downloads;

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

        event(new DownloadTransferProgressUpdated(
            downloadTransferId: $transfer->id,
            fileId: $transfer->file_id,
            domain: $transfer->domain,
            status: $transfer->status,
            percent: $boundary
        ));
    }
}
