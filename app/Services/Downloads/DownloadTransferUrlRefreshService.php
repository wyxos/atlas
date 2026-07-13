<?php

namespace App\Services\Downloads;

use App\Events\DownloadTransferProgressUpdated;
use App\Jobs\Downloads\PumpDomainDownloads;
use App\Models\DownloadTransfer;
use Throwable;

final class DownloadTransferUrlRefreshService
{
    private const int EXPIRY_BUFFER_SECONDS = 30;

    public function __construct(
        private readonly DownloadTransferRuntimeStore $runtimeStore,
        private readonly DownloadTransferActionTransition $actionTransition,
        private readonly DownloadUrlResolver $downloadUrlResolver,
    ) {}

    public function refreshBeforeRequest(DownloadTransfer $transfer): bool
    {
        $runtimeContext = $this->runtimeStore->getForTransfer($transfer->id);
        $expiresAt = $runtimeContext['provider_url_expires_at'] ?? null;
        if (! is_numeric($expiresAt) || (int) $expiresAt > now()->timestamp + self::EXPIRY_BUFFER_SECONDS) {
            return false;
        }

        return $this->refresh($transfer, $runtimeContext);
    }

    public function refreshAfterUnauthorized(DownloadTransfer $transfer): bool
    {
        return $this->refresh($transfer, $this->runtimeStore->getForTransfer($transfer->id));
    }

    /**
     * @param  array<string, mixed>  $runtimeContext
     */
    private function refresh(DownloadTransfer $transfer, array $runtimeContext): bool
    {
        if (($runtimeContext['provider_url_refresh_attempted'] ?? false) === true
            || ! $this->downloadUrlResolver->supportsProviderRefresh($transfer->file)) {
            return false;
        }

        $runtimeContext['provider_url_refresh_attempted'] = true;
        $this->runtimeStore->putForTransfer($transfer->id, $runtimeContext);

        $releasedDomain = $transfer->domain;
        if (! $this->actionTransition->refreshExpiredUrl($transfer, $runtimeContext)) {
            return false;
        }

        $transfer->refresh();
        try {
            event(new DownloadTransferProgressUpdated(
                DownloadTransferPayload::forProgress($transfer, 0)
            ));
        } catch (Throwable) {
            // Broadcast errors shouldn't fail URL refreshes.
        }

        foreach (array_unique(array_filter([$releasedDomain, $transfer->domain])) as $domain) {
            PumpDomainDownloads::dispatch($domain);
        }

        return true;
    }
}
