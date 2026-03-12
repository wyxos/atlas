<?php

namespace App\Services\Downloads;

use App\Models\DownloadTransfer;

use function data_get;

final class DownloadTransferTempDirectory
{
    public function standard(int $transferId): string
    {
        return $this->root().'/transfer-'.$transferId;
    }

    public function ytDlpAttempt(int $transferId, int $attempt): string
    {
        if ($attempt <= 0) {
            return $this->standard($transferId);
        }

        return $this->standard($transferId).'-attempt-'.$attempt;
    }

    public function forTransfer(DownloadTransfer $transfer): string
    {
        if ($this->isYtDlpTransfer($transfer)) {
            return $this->ytDlpAttempt($transfer->id, (int) ($transfer->attempt ?? 0));
        }

        return $this->standard($transfer->id);
    }

    private function isYtDlpTransfer(DownloadTransfer $transfer): bool
    {
        return data_get($transfer->file?->listing_metadata, 'download_via') === 'yt-dlp';
    }

    private function root(): string
    {
        return rtrim((string) config('downloads.tmp_dir'), '/');
    }
}
