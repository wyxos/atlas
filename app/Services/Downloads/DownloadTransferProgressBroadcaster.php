<?php

namespace App\Services\Downloads;

use App\Enums\DownloadTransferStatus;
use App\Events\DownloadTransferProgressUpdated;
use App\Models\DownloadTransfer;
use App\Models\File;
use Illuminate\Support\Facades\DB;

class DownloadTransferProgressBroadcaster
{
    public function maybeBroadcast(int $transferId, ?int $attempt = null): void
    {
        $progress = DB::transaction(function () use ($transferId, $attempt): ?array {
            $transfer = DownloadTransfer::query()->lockForUpdate()->find($transferId);
            if (! $transfer || ! $transfer->bytes_total) {
                return null;
            }

            if ($attempt !== null && (int) ($transfer->attempt ?? 0) !== $attempt) {
                return null;
            }

            if (in_array($transfer->status, [DownloadTransferStatus::PAUSED, DownloadTransferStatus::CANCELED], true)) {
                return null;
            }

            $percent = (int) floor(($transfer->bytes_downloaded / $transfer->bytes_total) * 100);
            $boundary = intdiv(max(0, min(100, $percent)), 5) * 5;
            if ($boundary <= (int) $transfer->last_broadcast_percent) {
                return null;
            }

            $transfer->forceFill(['last_broadcast_percent' => $boundary])->save();
            File::query()->whereKey($transfer->file_id)->update([
                'download_progress' => $boundary,
                'updated_at' => now(),
            ]);

            return [$transfer, $boundary];
        });

        if ($progress === null) {
            return;
        }

        [$transfer, $boundary] = $progress;

        try {
            event(new DownloadTransferProgressUpdated(
                DownloadTransferPayload::forProgress($transfer, $boundary)
            ));
        } catch (\Throwable) {
            // Broadcast errors shouldn't fail downloads.
        }
    }
}
