<?php

namespace App\Services\Downloads;

use App\Models\DownloadTransfer;

final class DownloadTransferTempDirectory
{
    public function standard(int $transferId): string
    {
        return $this->root().'/transfer-'.$transferId;
    }

    public function attempt(int $transferId, int $attempt): string
    {
        if ($attempt <= 0) {
            return $this->standard($transferId);
        }

        return $this->standard($transferId).'-attempt-'.$attempt;
    }

    public function ytDlpAttempt(int $transferId, int $attempt): string
    {
        return $this->attempt($transferId, $attempt);
    }

    public function forTransfer(DownloadTransfer $transfer): string
    {
        return $this->attempt($transfer->id, (int) ($transfer->attempt ?? 0));
    }

    private function root(): string
    {
        return rtrim((string) config('downloads.tmp_dir'), '/');
    }
}
