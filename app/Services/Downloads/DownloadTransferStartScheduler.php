<?php

namespace App\Services\Downloads;

use Illuminate\Support\Facades\Cache;

final class DownloadTransferStartScheduler
{
    private const string LOCK_PREFIX = 'downloads:domain-start-scheduler-lock:';

    private const string NEXT_AVAILABLE_PREFIX = 'downloads:domain-start-next-available:';

    public function reserveDelay(string $domain): int
    {
        $gapSeconds = max(0, (int) config('downloads.domain_start_gap_seconds', 0));
        $normalizedDomain = strtolower(trim($domain));

        if ($gapSeconds === 0 || $normalizedDomain === '') {
            return 0;
        }

        $lock = Cache::lock(self::LOCK_PREFIX.$normalizedDomain, 5);
        if (! $lock->get()) {
            return 1;
        }

        try {
            $now = now()->getTimestamp();
            $nextAvailableAt = (int) Cache::get(self::NEXT_AVAILABLE_PREFIX.$normalizedDomain, 0);
            $scheduledAt = max($now, $nextAvailableAt);
            $delay = max(0, $scheduledAt - $now);

            Cache::put(
                self::NEXT_AVAILABLE_PREFIX.$normalizedDomain,
                $scheduledAt + $gapSeconds,
                now()->addSeconds(max(60, $gapSeconds + 60)),
            );

            return $delay;
        } finally {
            $lock->release();
        }
    }
}
