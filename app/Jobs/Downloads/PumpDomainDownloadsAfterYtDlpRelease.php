<?php

namespace App\Jobs\Downloads;

use App\Models\DownloadTransfer;
use App\Services\Downloads\DownloadTransferExecutionLock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PumpDomainDownloadsAfterYtDlpRelease implements ShouldQueue
{
    use Queueable;

    private const POLL_DELAY_SECONDS = 5;

    public function __construct(public int $downloadTransferId, public string $releasedDomain)
    {
        $this->onQueue('downloads');
    }

    public function handle(?DownloadTransferExecutionLock $executionLock = null): void
    {
        $executionLock ??= app(DownloadTransferExecutionLock::class);

        if ($executionLock->isYtDlpActive($this->downloadTransferId)) {
            $this->pollAgain();

            return;
        }

        try {
            $currentDomain = DownloadTransfer::query()
                ->whereKey($this->downloadTransferId)
                ->value('domain');
        } catch (Throwable) {
            $this->pollAgain();

            return;
        }

        $domains = array_values(array_unique(array_filter([
            trim($this->releasedDomain),
            is_string($currentDomain) ? trim($currentDomain) : '',
        ])));

        foreach ($domains as $domain) {
            PumpDomainDownloads::dispatch($domain);
        }
    }

    private function pollAgain(): void
    {
        self::dispatch($this->downloadTransferId, $this->releasedDomain)
            ->delay(now()->addSeconds(self::POLL_DELAY_SECONDS));
    }
}
