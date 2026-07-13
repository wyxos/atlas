<?php

namespace App\Services\Downloads;

use App\Enums\DownloadTransferStatus;
use App\Models\DownloadChunk;
use App\Models\DownloadTransfer;
use Closure;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class DownloadTransferActionTransition
{
    public function __construct(
        private readonly DownloadTransferExecutionLock $executionLock,
        private readonly DownloadTransferTempDirectory $tempDirectory,
        private readonly DownloadUrlResolver $downloadUrlResolver,
        private readonly DownloadTransferRuntimeStore $runtimeStore,
    ) {}

    public function pause(DownloadTransfer $transfer): bool
    {
        return $this->locked($transfer, [
            DownloadTransferStatus::PENDING,
            DownloadTransferStatus::QUEUED,
            DownloadTransferStatus::PREPARING,
            DownloadTransferStatus::DOWNLOADING,
            DownloadTransferStatus::ASSEMBLING,
        ], function (DownloadTransfer $current): void {
            $current->forceFill(['status' => DownloadTransferStatus::PAUSED])->save();
            $this->cancelBatch($current);
        });
    }

    public function cancel(DownloadTransfer $transfer): bool
    {
        return $this->locked($transfer, [
            DownloadTransferStatus::PENDING,
            DownloadTransferStatus::QUEUED,
            DownloadTransferStatus::PREPARING,
            DownloadTransferStatus::DOWNLOADING,
            DownloadTransferStatus::ASSEMBLING,
            DownloadTransferStatus::PAUSED,
        ], function (DownloadTransfer $current): void {
            $current->forceFill(['status' => DownloadTransferStatus::CANCELED])->save();
            $this->cancelBatch($current);
            $this->cleanupOwnedArtifacts($current);
            $current->file->forceFill(['download_progress' => 0])->save();
        });
    }

    /**
     * @param  array<string, mixed>  $runtimeContext
     */
    public function resumeFromScratch(DownloadTransfer $transfer, array $runtimeContext = []): bool
    {
        return $this->resetFromScratch($transfer, [DownloadTransferStatus::PAUSED], $runtimeContext);
    }

    /**
     * @param  array<string, mixed>  $runtimeContext
     */
    public function restart(DownloadTransfer $transfer, array $runtimeContext = []): bool
    {
        return $this->resetFromScratch($transfer, [
            DownloadTransferStatus::FAILED,
            DownloadTransferStatus::CANCELED,
            DownloadTransferStatus::COMPLETED,
        ], $runtimeContext);
    }

    /**
     * @param  array<string, mixed>  $runtimeContext
     */
    public function refreshExpiredUrl(DownloadTransfer $transfer, array $runtimeContext): bool
    {
        return $this->resetFromScratch(
            $transfer,
            [
                DownloadTransferStatus::QUEUED,
                DownloadTransferStatus::PREPARING,
                DownloadTransferStatus::DOWNLOADING,
            ],
            $runtimeContext,
            requireProviderResolution: true,
        );
    }

    public function resumeFailed(DownloadTransfer $transfer): bool
    {
        return $this->locked($transfer, [DownloadTransferStatus::FAILED], function (DownloadTransfer $current): void {
            $this->cancelBatch($current);
            $current->forceFill([
                'status' => DownloadTransferStatus::PENDING,
                'queued_at' => null,
                'started_at' => null,
                'finished_at' => null,
                'failed_at' => null,
                'batch_id' => null,
                'error' => null,
            ])->save();
        }, fn (DownloadTransfer $current): bool => DownloadTransferActionAvailability::canResume($current));
    }

    public function shouldDeferPump(DownloadTransfer $transfer): bool
    {
        $transfer->loadMissing('file');

        return YtDlpUnsupportedUrlFallback::usesYtDlp($transfer)
            && $this->executionLock->isYtDlpActive($transfer->id);
    }

    /**
     * @param  list<string>  $statuses
     * @param  array<string, mixed>  $runtimeContext
     */
    private function resetFromScratch(
        DownloadTransfer $transfer,
        array $statuses,
        array $runtimeContext = [],
        bool $requireProviderResolution = false,
    ): bool {
        $reset = false;
        $updatedRuntimeContext = $runtimeContext;
        $updated = $this->locked($transfer, $statuses, function (DownloadTransfer $current) use (
            &$reset,
            &$updatedRuntimeContext,
            $runtimeContext,
            $requireProviderResolution,
        ): void {
            $resolvedDownload = $current->file->url
                ? $this->downloadUrlResolver->resolve($current->file, $runtimeContext)
                : null;
            $requiresProviderResolution = $requireProviderResolution
                || $this->downloadUrlResolver->supportsProviderRefresh($current->file);
            if ($requiresProviderResolution && ! $resolvedDownload?->providerResolved) {
                return;
            }

            $this->cancelBatch($current);
            $this->cleanupOwnedArtifacts($current);
            $updates = [
                'status' => DownloadTransferStatus::PENDING,
                'attempt' => ((int) ($current->attempt ?? 0)) + 1,
                'bytes_total' => null,
                'bytes_downloaded' => 0,
                'last_broadcast_percent' => 0,
                'queued_at' => null,
                'started_at' => null,
                'finished_at' => null,
                'failed_at' => null,
                'batch_id' => null,
                'error' => null,
            ];
            if ($resolvedDownload !== null) {
                $freshUrl = $resolvedDownload->url;
                $updates['url'] = $freshUrl;
                $host = parse_url($freshUrl, PHP_URL_HOST);
                if (is_string($host) && $host !== '') {
                    $updates['domain'] = strtolower($host);
                }

                if ($resolvedDownload->providerResolved) {
                    $updatedRuntimeContext['provider_url_refresh_attempted'] = (bool) ($runtimeContext['provider_url_refresh_attempted'] ?? false);
                    if ($resolvedDownload->expiresAt !== null) {
                        $updatedRuntimeContext['provider_url_expires_at'] = $resolvedDownload->expiresAt->timestamp;
                    } else {
                        unset($updatedRuntimeContext['provider_url_expires_at']);
                    }
                }
            }

            $current->forceFill($updates)->save();
            $current->file->forceFill(['download_progress' => 0])->save();
            $reset = true;
        });

        if (! $updated || ! $reset) {
            return false;
        }

        if ($updatedRuntimeContext !== []) {
            $this->runtimeStore->putForTransfer($transfer->id, $updatedRuntimeContext);
        }

        return true;
    }

    /**
     * @param  list<string>  $statuses
     * @param  Closure(DownloadTransfer): void  $callback
     * @param  (Closure(DownloadTransfer): bool)|null  $guard
     */
    private function locked(DownloadTransfer $transfer, array $statuses, Closure $callback, ?Closure $guard = null): bool
    {
        $expectedAttempt = (int) ($transfer->attempt ?? 0);
        $updated = DB::transaction(function () use ($transfer, $expectedAttempt, $statuses, $callback, $guard): bool {
            $current = DownloadTransfer::query()->with('file')->lockForUpdate()->find($transfer->id);
            if (! DownloadTransferGeneration::matches($current, $expectedAttempt, $statuses)
                || ! $current?->file
                || ($guard && ! $guard($current))) {
                return false;
            }

            $callback($current);

            return true;
        });
        if ($updated) {
            $transfer->refresh();
        }

        return $updated;
    }

    private function cancelBatch(DownloadTransfer $transfer): void
    {
        if ($transfer->batch_id && ($batch = Bus::findBatch($transfer->batch_id))) {
            $batch->cancel();
        }
    }

    private function cleanupOwnedArtifacts(DownloadTransfer $transfer): void
    {
        DownloadChunk::query()->where('download_transfer_id', $transfer->id)->delete();
        if ($this->shouldDeferPump($transfer)) {
            return;
        }

        $disk = Storage::disk(config('downloads.disk'));
        $directory = $this->tempDirectory->forTransfer($transfer);
        if ($disk->exists($directory)) {
            $disk->deleteDirectory($directory);
        }
    }
}
