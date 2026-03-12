<?php

namespace App\Services\Downloads;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

final class DownloadTransferExecutionLock
{
    public function acquireYtDlp(int $transferId, int $seconds): ?Lock
    {
        $lock = Cache::lock($this->ytDlpLockKey($transferId), max(5, $seconds));

        return $lock->get() ? $lock : null;
    }

    public function isYtDlpActive(int $transferId): bool
    {
        $lock = Cache::lock($this->ytDlpLockKey($transferId), 1);

        if (! $lock->get()) {
            return true;
        }

        $lock->release();

        return false;
    }

    private function ytDlpLockKey(int $transferId): string
    {
        return 'downloads:transfer:yt-dlp:'.$transferId;
    }
}
