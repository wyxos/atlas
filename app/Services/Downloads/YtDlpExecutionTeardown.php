<?php

namespace App\Services\Downloads;

use App\Enums\DownloadTransferStatus;
use App\Jobs\Downloads\PumpDomainDownloads;
use App\Models\DownloadTransfer;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Storage;
use Throwable;

class YtDlpExecutionTeardown
{
    public function __construct(private readonly DownloadTransferTempDirectory $tempDirectory) {}

    public function finish(
        int $transferId,
        int $attempt,
        string $originalDomain,
        ?string $runtimeCookieJarPath,
        ?Lock $lock,
        bool $shouldPumpAfterRelease,
    ): void {
        try {
            if ($shouldPumpAfterRelease) {
                $this->cleanupAttemptIfNoLongerOwned($transferId, $attempt);
            }
        } catch (Throwable) {
            // Cleanup is best effort, but credentials and the execution lock must always be released.
        } finally {
            $this->releaseExecutionResources($runtimeCookieJarPath, $lock);
        }

        if ($shouldPumpAfterRelease) {
            $this->pumpDomainsAfterRelease($transferId, $originalDomain);
        }
    }

    private function cleanupAttemptIfNoLongerOwned(int $transferId, int $attempt): void
    {
        $state = DownloadTransfer::query()
            ->whereKey($transferId)
            ->first(['status', 'attempt']);

        if ($state
            && (int) ($state->attempt ?? 0) === $attempt
            && in_array($state->status, [
                DownloadTransferStatus::PENDING,
                DownloadTransferStatus::QUEUED,
                DownloadTransferStatus::PREPARING,
                DownloadTransferStatus::DOWNLOADING,
                DownloadTransferStatus::ASSEMBLING,
                DownloadTransferStatus::FAILED,
            ], true)) {
            return;
        }

        $disk = Storage::disk(config('downloads.disk'));
        $directory = $this->tempDirectory->ytDlpAttempt($transferId, $attempt);
        if ($disk->exists($directory)) {
            $disk->deleteDirectory($directory);
        }
    }

    private function releaseExecutionResources(?string $runtimeCookieJarPath, ?Lock $lock): void
    {
        $cookieCleanupFailure = false;

        try {
            if (is_string($runtimeCookieJarPath) && $runtimeCookieJarPath !== '' && is_file($runtimeCookieJarPath)) {
                if (! @unlink($runtimeCookieJarPath)) {
                    @file_put_contents($runtimeCookieJarPath, '', LOCK_EX);
                    @unlink($runtimeCookieJarPath);
                    clearstatcache(true, $runtimeCookieJarPath);
                    $cookieCleanupFailure = is_file($runtimeCookieJarPath);
                }
            }
        } catch (Throwable) {
            $cookieCleanupFailure = true;
        } finally {
            if ($lock instanceof Lock) {
                try {
                    $lock->release();
                } catch (Throwable) {
                    // The cache lock expires automatically if its backend is unavailable during release.
                }
            }
        }

        if ($cookieCleanupFailure) {
            try {
                report(new \RuntimeException('Atlas could not securely remove a yt-dlp runtime cookie jar.'));
            } catch (Throwable) {
                // Reporting must not interfere with transfer cleanup.
            }
        }
    }

    private function pumpDomainsAfterRelease(int $transferId, string $originalDomain): void
    {
        $currentDomain = null;
        try {
            $currentDomain = DownloadTransfer::query()
                ->whereKey($transferId)
                ->value('domain');
        } catch (Throwable) {
            // The released domain can still be pumped if the transfer lookup fails.
        }

        $domains = array_unique(array_filter([
            $originalDomain,
            is_string($currentDomain) ? $currentDomain : null,
        ]));

        foreach ($domains as $domain) {
            try {
                PumpDomainDownloads::dispatch($domain);
            } catch (Throwable) {
                // A later queue sweep can recover if dispatch is temporarily unavailable.
            }
        }
    }
}
