<?php

namespace App\Jobs\Downloads;

use App\Enums\DownloadTransferStatus;
use App\Events\DownloadTransferProgressUpdated;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Services\Downloads\DownloadFailureMessage;
use App\Services\Downloads\DownloadTransferGeneration;
use App\Services\Downloads\DownloadTransferPayload;
use App\Services\Downloads\DownloadTransferProgressBroadcaster;
use App\Services\Downloads\DownloadTransferRequestOptions;
use App\Services\Downloads\DownloadTransferRuntimeStore;
use App\Services\Downloads\DownloadTransferTempDirectory;
use App\Services\Downloads\DownloadTransferUrlRefreshService;
use App\Services\Downloads\FileDownloadFinalizer;
use App\Services\Downloads\NativeFallbackMediaValidator;
use App\Services\Downloads\YtDlpUnsupportedUrlFallback;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DownloadTransferSingleStream implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public ?int $attempt = null;

    public function __construct(
        public int $downloadTransferId,
        public ?string $contentTypeHeader = null,
        ?int $attempt = null,
    ) {
        $this->attempt = $attempt;
        $this->onQueue('downloads');
    }

    /**
     * Execute the job.
     */
    public function handle(
        FileDownloadFinalizer $finalizer,
        DownloadTransferProgressBroadcaster $broadcaster,
        ?DownloadTransferRequestOptions $requestOptions = null,
        ?NativeFallbackMediaValidator $mediaValidator = null,
        ?DownloadTransferTempDirectory $tempDirectory = null,
        ?DownloadTransferUrlRefreshService $urlRefreshService = null,
    ): void {
        $requestOptions ??= app(DownloadTransferRequestOptions::class);
        $mediaValidator ??= app(NativeFallbackMediaValidator::class);
        $tempDirectory ??= app(DownloadTransferTempDirectory::class);
        $urlRefreshService ??= app(DownloadTransferUrlRefreshService::class);
        $this->attempt ??= 0;

        $transfer = DownloadTransfer::query()->with('file')->find($this->downloadTransferId);
        if (! $transfer || ! $transfer->file) {
            return;
        }

        $fh = null;

        try {
            if (! DownloadTransferGeneration::matches($transfer, $this->attempt, [DownloadTransferStatus::DOWNLOADING])) {
                return;
            }

            if ($urlRefreshService->refreshBeforeRequest($transfer)) {
                return;
            }

            $headers = $requestOptions->httpHeaders($transfer);

            $timeout = (int) config('downloads.http_timeout_seconds');

            $response = Http::timeout($timeout)->withHeaders($headers)
                ->withOptions(['stream' => true])
                ->get($transfer->url);

            $transfer = DownloadTransferGeneration::fresh($transfer->id, $this->attempt, [DownloadTransferStatus::DOWNLOADING]);
            if (! $transfer) {
                return;
            }

            if (! $this->isValidResponse($response)) {
                if (in_array($response->status(), [401, 403], true)
                    && $urlRefreshService->refreshAfterUnauthorized($transfer)) {
                    return;
                }

                if ($this->shouldRetryStatus($response->status())) {
                    $this->scheduleRetry($transfer, "Received HTTP {$response->status()} while downloading.");

                    return;
                }

                $this->failTransfer($transfer, "Invalid download response (status {$response->status()}).");

                return;
            }

            $nativeRejection = YtDlpUnsupportedUrlFallback::isNativeTransfer($transfer)
                ? $mediaValidator->rejectionForContentType($response->header('Content-Type'))
                : null;
            if ($nativeRejection !== null) {
                $this->failTransfer($transfer, $nativeRejection);

                return;
            }

            if ($transfer->error !== null || $transfer->failed_at !== null) {
                $updated = DownloadTransferGeneration::runLocked(
                    $transfer->id,
                    $this->attempt,
                    [DownloadTransferStatus::DOWNLOADING],
                    function (DownloadTransfer $current): void {
                        $current->forceFill(['failed_at' => null, 'error' => null])->save();
                    },
                );
                if (! $updated) {
                    return;
                }
                $transfer = DownloadTransferGeneration::fresh($transfer->id, $this->attempt, [DownloadTransferStatus::DOWNLOADING]);
                if (! $transfer) {
                    return;
                }
            }

            if (! $transfer->bytes_total) {
                $contentLength = $response->header('Content-Length');
                $totalBytes = is_numeric($contentLength) && (int) $contentLength > 0 ? (int) $contentLength : null;
                if ($totalBytes) {
                    $updated = DownloadTransferGeneration::runLocked(
                        $transfer->id,
                        $this->attempt,
                        [DownloadTransferStatus::DOWNLOADING],
                        function (DownloadTransfer $current) use ($totalBytes): void {
                            $current->forceFill([
                                'bytes_total' => $totalBytes,
                                'bytes_downloaded' => 0,
                                'last_broadcast_percent' => 0,
                            ])->save();
                            if (! $current->file->size || $current->file->size <= 0) {
                                $current->file->update(['size' => $totalBytes, 'updated_at' => now()]);
                            }
                        },
                    );
                    if (! $updated) {
                        return;
                    }
                    $transfer = DownloadTransferGeneration::fresh($transfer->id, $this->attempt, [DownloadTransferStatus::DOWNLOADING]);
                    if (! $transfer) {
                        return;
                    }
                }
            }
            $disk = Storage::disk(config('downloads.disk'));

            $tmpDir = $tempDirectory->attempt($transfer->id, $this->attempt);
            $tmpPath = "{$tmpDir}/single.tmp";

            if (! $disk->exists($tmpDir)) {
                $disk->makeDirectory($tmpDir, 0755, true);
            }

            $absoluteTmpPath = $disk->path($tmpPath);
            $fh = fopen($absoluteTmpPath, 'wb');
            if (! $fh) {
                $this->failTransfer($transfer, 'Unable to open temp file for writing.');

                return;
            }

            $body = $response->toPsrResponse()->getBody();
            $bufferSize = 1024 * 1024;

            $bytesWritten = 0;
            $bytesSinceBroadcastCheck = 0;
            $pendingProgressBytes = 0;

            while (! $body->eof()) {
                $buffer = $body->read($bufferSize);
                if ($buffer === '') {
                    break;
                }

                $written = fwrite($fh, $buffer);
                if ($written === false) {
                    fclose($fh);
                    $fh = null;
                    $this->failTransfer($transfer, 'Failed writing download to disk.');

                    return;
                }

                $bytesWritten += $written;
                $bytesSinceBroadcastCheck += $written;
                $pendingProgressBytes += $written;

                if ($bytesSinceBroadcastCheck >= (2 * 1024 * 1024)) {
                    $bytesSinceBroadcastCheck = 0;
                    $this->flushProgress($transfer->id, $pendingProgressBytes);
                    if ($this->shouldStop($transfer->id, $fh)) {
                        return;
                    }
                    $broadcaster->maybeBroadcast($transfer->id, $this->attempt);
                }
            }

            $this->flushProgress($transfer->id, $pendingProgressBytes);

            fclose($fh);
            $fh = null;

            $resolvedContentType = $this->contentTypeHeader ?? $response->header('Content-Type');
            $nativeRejection = YtDlpUnsupportedUrlFallback::isNativeTransfer($transfer)
                ? $mediaValidator->rejectionForArtifact($absoluteTmpPath, $resolvedContentType)
                : null;
            if ($nativeRejection !== null) {
                $this->failTransfer($transfer, $nativeRejection);
                $this->cleanupTempArtifacts($tmpDir);

                return;
            }

            $finalized = DownloadTransferGeneration::runLocked(
                $transfer->id,
                $this->attempt,
                [DownloadTransferStatus::DOWNLOADING],
                function (DownloadTransfer $current) use ($finalizer, $tmpPath, $resolvedContentType): void {
                    $current->forceFill(['status' => DownloadTransferStatus::ASSEMBLING])->save();
                    $finalizer->finalize($current->file, $tmpPath, $resolvedContentType, false);
                    $current->forceFill([
                        'status' => DownloadTransferStatus::PREVIEWING,
                        'last_broadcast_percent' => 100,
                        'finished_at' => null,
                        'failed_at' => null,
                        'error' => null,
                    ])->save();
                    File::query()->whereKey($current->file_id)->update([
                        'download_progress' => 100,
                        'updated_at' => now(),
                    ]);
                    app(DownloadTransferRuntimeStore::class)->forgetForTransfer($current->id);
                },
            );
            if (! $finalized) {
                $this->cleanupTempArtifacts($tmpDir);

                return;
            }

            $transfer = DownloadTransferGeneration::fresh($transfer->id, $this->attempt, [DownloadTransferStatus::PREVIEWING]);
            if (! $transfer) {
                $this->cleanupTempArtifacts($tmpDir);

                return;
            }

            try {
                event(new DownloadTransferProgressUpdated(
                    DownloadTransferPayload::forProgress($transfer, 100)
                ));
            } catch (\Throwable) {
                // Broadcast errors shouldn't fail downloads.
            }

            GenerateTransferPreview::dispatch($transfer->id);

            $this->cleanupTempArtifacts($tmpDir);

            PumpDomainDownloads::dispatch($transfer->domain);
        } catch (Throwable $e) {
            if (is_resource($fh)) {
                fclose($fh);
            }

            if ($this->shouldRetryException($e)) {
                $this->scheduleRetry($transfer, $e->getMessage());

                return;
            }

            $this->failTransfer($transfer, $e->getMessage());
        }
    }

    private function shouldStop(int $transferId, $fh): bool
    {
        $isCurrent = DownloadTransfer::query()->whereKey($transferId)
            ->where('attempt', $this->attempt)
            ->where('status', DownloadTransferStatus::DOWNLOADING)
            ->exists();
        if ($isCurrent) {
            return false;
        }

        if (is_resource($fh)) {
            fclose($fh);
        }

        return true;
    }

    private function isValidResponse(Response $response): bool
    {
        return $response->successful();
    }

    private function flushProgress(int $transferId, int &$pendingBytes): void
    {
        if ($pendingBytes <= 0) {
            return;
        }

        DownloadTransfer::query()->whereKey($transferId)
            ->where('attempt', $this->attempt)
            ->where('status', DownloadTransferStatus::DOWNLOADING)
            ->increment('bytes_downloaded', $pendingBytes);
        $pendingBytes = 0;
    }

    private function failTransfer(DownloadTransfer $transfer, string $message): void
    {
        $failed = DownloadTransferGeneration::runLocked($transfer->id, $this->attempt, [
            DownloadTransferStatus::DOWNLOADING,
            DownloadTransferStatus::ASSEMBLING,
        ], function (DownloadTransfer $current) use ($message): void {
            $current->forceFill([
                'status' => DownloadTransferStatus::FAILED,
                'failed_at' => now(),
                'error' => DownloadFailureMessage::normalize($message),
            ])->save();
            app(DownloadTransferRuntimeStore::class)->forgetForTransfer($current->id);
        });
        if (! $failed) {
            return;
        }

        $updated = DownloadTransfer::query()->find($transfer->id);
        if ($updated) {
            try {
                event(new DownloadTransferProgressUpdated(
                    DownloadTransferPayload::forProgress($updated, (int) ($updated->last_broadcast_percent ?? 0))
                ));
            } catch (Throwable) {
                // Broadcast errors shouldn't fail downloads.
            }
        }

        PumpDomainDownloads::dispatch($transfer->domain);
    }

    private function shouldRetryStatus(int $status): bool
    {
        if ($this->attempts() >= $this->tries) {
            return false;
        }

        return $status === 408
            || $status === 425
            || $status === 429
            || ($status >= 500 && $status <= 599);
    }

    private function shouldRetryException(Throwable $e): bool
    {
        if ($this->attempts() >= $this->tries) {
            return false;
        }

        if ($e instanceof ConnectionException) {
            return true;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'timed out')
            || str_contains($message, 'curl error 28')
            || str_contains($message, 'connection refused')
            || str_contains($message, 'temporarily unavailable')
            || str_contains($message, 'unable to read from stream');
    }

    private function scheduleRetry(DownloadTransfer $transfer, string $reason): void
    {
        $delay = max(1, (int) $this->backoff);
        $attempt = max(1, $this->attempts());
        $message = $this->retryMessage($attempt, $delay, $reason);
        $scheduled = DownloadTransferGeneration::runLocked(
            $transfer->id,
            $this->attempt,
            [DownloadTransferStatus::DOWNLOADING],
            function (DownloadTransfer $current) use ($message): void {
                $current->forceFill([
                    'bytes_downloaded' => 0,
                    'last_broadcast_percent' => 0,
                    'failed_at' => null,
                    'error' => $message,
                ])->save();
                File::query()->whereKey($current->file_id)->update([
                    'download_progress' => 0,
                    'updated_at' => now(),
                ]);
            },
        );
        if (! $scheduled) {
            return;
        }

        $updated = DownloadTransfer::query()->find($transfer->id);
        if ($updated) {
            try {
                event(new DownloadTransferProgressUpdated(
                    DownloadTransferPayload::forProgress($updated, (int) ($updated->last_broadcast_percent ?? 0))
                ));
            } catch (Throwable) {
                // Broadcast errors shouldn't fail downloads.
            }
        }

        $this->release($delay);
    }

    private function retryMessage(int $attempt, int $delay, string $reason): string
    {
        $cleanReason = DownloadFailureMessage::normalize($reason, 'Transient network error.');
        $cleanReason = trim(str_replace(["\r", "\n"], ' ', $cleanReason));

        return "Retry {$attempt}/{$this->tries} scheduled in {$delay}s: {$cleanReason}";
    }

    private function cleanupTempArtifacts(string $tmpDir): void
    {
        $disk = Storage::disk(config('downloads.disk'));
        if ($disk->exists($tmpDir)) {
            $disk->deleteDirectory($tmpDir);
        }
    }

    // Progress broadcasting is handled by DownloadTransferProgressBroadcaster.
}
