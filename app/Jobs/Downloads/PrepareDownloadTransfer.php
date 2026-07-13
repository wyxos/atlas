<?php

namespace App\Jobs\Downloads;

use App\Enums\DownloadTransferStatus;
use App\Events\DownloadTransferProgressUpdated;
use App\Events\DownloadTransferQueued;
use App\Models\DownloadTransfer;
use App\Services\Downloads\DownloadFailureMessage;
use App\Services\Downloads\DownloadTransferBatchFailureHandler;
use App\Services\Downloads\DownloadTransferChunkPlanner;
use App\Services\Downloads\DownloadTransferExecutionLock;
use App\Services\Downloads\DownloadTransferGeneration;
use App\Services\Downloads\DownloadTransferPayload;
use App\Services\Downloads\DownloadTransferRequestOptions;
use App\Services\Downloads\DownloadTransferUrlRefreshService;
use App\Services\Downloads\NativeFallbackMediaValidator;
use App\Services\Downloads\YtDlpUnsupportedUrlFallback;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Throwable;

use function data_get;

class PrepareDownloadTransfer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public ?int $attempt = null;

    public function __construct(public int $downloadTransferId, ?int $attempt = null)
    {
        $this->attempt = $attempt;
        $this->onQueue('downloads');
    }

    public function handle(
        ?DownloadTransferRequestOptions $requestOptions = null,
        ?DownloadTransferExecutionLock $executionLock = null,
        ?NativeFallbackMediaValidator $mediaValidator = null,
        ?DownloadTransferChunkPlanner $chunkPlanner = null,
        ?DownloadTransferUrlRefreshService $urlRefreshService = null,
    ): void {
        $requestOptions ??= app(DownloadTransferRequestOptions::class);
        $executionLock ??= app(DownloadTransferExecutionLock::class);
        $mediaValidator ??= app(NativeFallbackMediaValidator::class);
        $chunkPlanner ??= app(DownloadTransferChunkPlanner::class);
        $urlRefreshService ??= app(DownloadTransferUrlRefreshService::class);

        $transfer = DownloadTransfer::query()->with('file')->find($this->downloadTransferId);
        if (! $transfer || ! $transfer->file) {
            return;
        }
        $this->attempt ??= (int) ($transfer->attempt ?? 0);

        try {
            if (! DownloadTransferGeneration::matches($transfer, $this->attempt, [
                DownloadTransferStatus::QUEUED,
                DownloadTransferStatus::PREPARING,
            ])) {
                return;
            }

            if ($urlRefreshService->refreshBeforeRequest($transfer)) {
                return;
            }

            $isNativeYtDlpFallback = YtDlpUnsupportedUrlFallback::isNativeTransfer($transfer);
            if (data_get($transfer->file->listing_metadata, 'download_via') === 'yt-dlp' && ! $isNativeYtDlpFallback) {
                if ($executionLock->isYtDlpActive($transfer->id)) {
                    $this->requeueWhileYtDlpIsActive($transfer);

                    return;
                }

                $updated = DownloadTransferGeneration::update($transfer->id, $this->attempt, [
                    DownloadTransferStatus::QUEUED,
                    DownloadTransferStatus::PREPARING,
                ], [
                    'bytes_total' => null,
                    'bytes_downloaded' => max(0, (int) ($transfer->bytes_downloaded ?? 0)),
                    'last_broadcast_percent' => max(0, min(99, (int) ($transfer->last_broadcast_percent ?? 0))),
                    'status' => DownloadTransferStatus::DOWNLOADING,
                    'started_at' => $transfer->started_at ?? now(),
                    'failed_at' => null,
                    'error' => null,
                ]);
                if ($updated === 0) {
                    return;
                }

                $transfer = DownloadTransferGeneration::fresh($transfer->id, $this->attempt, [DownloadTransferStatus::DOWNLOADING]);
                if (! $transfer) {
                    return;
                }
                $this->broadcastProgress($transfer);

                DownloadTransferYtDlp::dispatch($transfer->id, $this->attempt);

                return;
            }

            $updated = DownloadTransferGeneration::update($transfer->id, $this->attempt, [
                DownloadTransferStatus::QUEUED,
                DownloadTransferStatus::PREPARING,
            ], [
                'status' => DownloadTransferStatus::PREPARING,
                'failed_at' => null,
                'error' => null,
            ]);
            if ($updated === 0) {
                return;
            }

            $transfer = DownloadTransferGeneration::fresh($transfer->id, $this->attempt, [DownloadTransferStatus::PREPARING]);
            if (! $transfer) {
                return;
            }
            $this->broadcastProgress($transfer);

            $url = $transfer->url;
            if (! is_string($url) || ! preg_match('/^https?:\/\//i', $url)) {
                $this->failTransfer($transfer, 'Invalid transfer URL. Only HTTP(S) URLs are supported.');

                return;
            }
            $headers = $requestOptions->httpHeaders($transfer);
            $timeout = (int) config('downloads.http_timeout_seconds');

            $head = Http::timeout($timeout)->withHeaders($headers)->head($url);
            $transfer = DownloadTransferGeneration::fresh($transfer->id, $this->attempt, [DownloadTransferStatus::PREPARING]);
            if (! $transfer) {
                return;
            }
            if (in_array($head->status(), [401, 403], true)
                && $urlRefreshService->refreshAfterUnauthorized($transfer)) {
                return;
            }

            $isNativeYtDlpFallback = YtDlpUnsupportedUrlFallback::isNativeTransfer($transfer);
            $contentType = $head->header('Content-Type');
            $contentLength = $head->header('Content-Length');
            $acceptRanges = $head->header('Accept-Ranges');
            $headStatus = $head->status();
            $headMime = is_string($contentType)
                ? strtolower(trim(explode(';', $contentType, 2)[0]))
                : '';
            $headLooksLikeHtml = in_array($headMime, ['text/html', 'application/xhtml+xml'], true);
            $nativeRejection = $isNativeYtDlpFallback
                ? $mediaValidator->rejectionForContentType($contentType)
                : null;
            if ($nativeRejection !== null) {
                $this->failTransfer($transfer, $nativeRejection);

                return;
            }

            $tagName = data_get($transfer->file->listing_metadata, 'tag_name');
            if (in_array($tagName, ['video', 'iframe'], true) && ! $isNativeYtDlpFallback) {
                // For video/iframe sources, treat blocked/empty HEAD probes like HTML pages and force yt-dlp.
                $headProbeUnavailable = $headStatus >= 400 || $headMime === '';
                if ($headLooksLikeHtml || $headProbeUnavailable) {
                    if ($executionLock->isYtDlpActive($transfer->id)) {
                        $this->requeueWhileYtDlpIsActive($transfer);

                        return;
                    }

                    $pageUrl = data_get($transfer->file->listing_metadata, 'page_url');
                    $transitioned = DownloadTransferGeneration::runLocked(
                        $transfer->id,
                        $this->attempt,
                        [DownloadTransferStatus::PREPARING],
                        function (DownloadTransfer $current) use ($headLooksLikeHtml, $pageUrl): void {
                            try {
                                $metadata = $current->file->listing_metadata;
                                $metadata = is_array($metadata) ? $metadata : [];
                                $metadata['download_via'] = 'yt-dlp';
                                $metadata['download_via_reason'] = $headLooksLikeHtml
                                    ? 'content-type-html'
                                    : 'head-probe-unavailable';
                                $current->file->forceFill(['listing_metadata' => $metadata])->save();
                            } catch (Throwable) {
                                // Metadata updates shouldn't block the download fallback.
                            }

                            $current->forceFill([
                                'url' => is_string($pageUrl) && $pageUrl !== '' ? $pageUrl : $current->url,
                                'bytes_total' => null,
                                'bytes_downloaded' => 0,
                                'last_broadcast_percent' => 0,
                                'status' => DownloadTransferStatus::DOWNLOADING,
                                'started_at' => $current->started_at ?? now(),
                                'failed_at' => null,
                                'error' => null,
                            ])->save();
                        },
                    );
                    if (! $transitioned) {
                        return;
                    }

                    $transfer = DownloadTransferGeneration::fresh($transfer->id, $this->attempt, [DownloadTransferStatus::DOWNLOADING]);
                    if (! $transfer) {
                        return;
                    }
                    $this->broadcastProgress($transfer);

                    DownloadTransferYtDlp::dispatch($transfer->id, $this->attempt);

                    return;
                }
            }

            $totalBytes = is_numeric($contentLength) && (int) $contentLength > 0 ? (int) $contentLength : null;
            $rangesSupported = is_string($acceptRanges) && str_contains(strtolower($acceptRanges), 'bytes');

            if (! $rangesSupported || $totalBytes === null) {
                $rangeProbe = Http::timeout($timeout)
                    ->withHeaders(array_merge($headers, ['Range' => 'bytes=0-0']))
                    ->withOptions(['stream' => true])
                    ->get($url);
                $transfer = DownloadTransferGeneration::fresh($transfer->id, $this->attempt, [DownloadTransferStatus::PREPARING]);
                if (! $transfer) {
                    return;
                }
                $rangeRejection = $isNativeYtDlpFallback
                    ? $mediaValidator->rejectionForContentType($rangeProbe->header('Content-Type'))
                    : null;
                if ($rangeRejection !== null) {
                    $this->failTransfer($transfer, $rangeRejection);

                    return;
                }
                if ($rangeProbe->status() === 206) {
                    $rangesSupported = true;
                    $contentType = $contentType ?? $rangeProbe->header('Content-Type');
                    $totalBytes = $totalBytes ?? $this->parseTotalBytesFromContentRange($rangeProbe->header('Content-Range'));
                }
            }

            if ($totalBytes === null) {
                $updated = DownloadTransferGeneration::update($transfer->id, $this->attempt, [DownloadTransferStatus::PREPARING], [
                    'bytes_total' => null,
                    'bytes_downloaded' => 0,
                    'last_broadcast_percent' => 0,
                    'status' => DownloadTransferStatus::DOWNLOADING,
                    'started_at' => $transfer->started_at ?? now(),
                    'failed_at' => null,
                    'error' => null,
                ]);
                if ($updated === 0) {
                    return;
                }

                $transfer = DownloadTransferGeneration::fresh($transfer->id, $this->attempt, [DownloadTransferStatus::DOWNLOADING]);
                if (! $transfer) {
                    return;
                }
                $this->broadcastProgress($transfer);

                DownloadTransferSingleStream::dispatch($transfer->id, $contentType, $this->attempt);

                return;
            }

            $transitioned = DownloadTransferGeneration::runLocked(
                $transfer->id,
                $this->attempt,
                [DownloadTransferStatus::PREPARING],
                function (DownloadTransfer $current) use ($totalBytes): void {
                    if (! $current->file->size || $current->file->size <= 0) {
                        $current->file->update(['size' => $totalBytes, 'updated_at' => now()]);
                    }
                    $current->forceFill([
                        'bytes_total' => $totalBytes,
                        'bytes_downloaded' => 0,
                        'last_broadcast_percent' => 0,
                        'status' => DownloadTransferStatus::DOWNLOADING,
                        'started_at' => $current->started_at ?? now(),
                        'failed_at' => null,
                        'error' => null,
                    ])->save();
                },
            );
            if (! $transitioned) {
                return;
            }

            $transfer = DownloadTransferGeneration::fresh($transfer->id, $this->attempt, [DownloadTransferStatus::DOWNLOADING]);
            if (! $transfer) {
                return;
            }
            $this->broadcastProgress($transfer);

            if (! $rangesSupported || $totalBytes < (int) config('downloads.min_bytes_for_chunking')) {
                DownloadTransferSingleStream::dispatch($transfer->id, $contentType, $this->attempt);

                return;
            }

            $chunkIds = $chunkPlanner->plan($transfer->id, $this->attempt, $totalBytes);
            if ($chunkIds === []) {
                return;
            }

            $jobs = array_map(fn (int $chunkId) => new DownloadTransferChunk(
                $transfer->id,
                $chunkId,
                $contentType,
                $this->attempt,
            ), $chunkIds);
            $transferId = $transfer->id;
            $transferDomain = $transfer->domain;
            $transferAttempt = $this->attempt;
            $queueConnection = (string) config('queue.default');

            $batch = Bus::batch($jobs)
                ->onConnection($queueConnection)
                ->onQueue('downloads')
                ->then(fn () => AssembleDownloadTransfer::dispatch($transferId, $contentType, $transferAttempt))
                ->catch(fn (Batch $batch, Throwable $e) => app(DownloadTransferBatchFailureHandler::class)->handle(
                    $transferId,
                    $transferAttempt,
                    $transferDomain,
                    $chunkIds,
                    $e,
                ))
                ->dispatch();

            DownloadTransferGeneration::update($transfer->id, $this->attempt, [DownloadTransferStatus::DOWNLOADING], [
                'batch_id' => $batch->id,
            ]);
        } catch (Throwable $e) {
            if ($this->shouldRetry($e)) {
                $this->scheduleRetry($transfer, $e->getMessage());

                return;
            }

            $this->failTransfer($transfer, $e->getMessage());
        }
    }

    private function parseTotalBytesFromContentRange(?string $contentRange): ?int
    {
        if (! $contentRange) {
            return null;
        }

        $parts = explode('/', $contentRange);
        if (count($parts) !== 2) {
            return null;
        }

        $total = trim($parts[1]);

        return is_numeric($total) ? (int) $total : null;
    }

    private function failTransfer(DownloadTransfer $transfer, string $message): void
    {
        $updatedCount = DownloadTransferGeneration::update($transfer->id, $this->attempt, [
            DownloadTransferStatus::QUEUED,
            DownloadTransferStatus::PREPARING,
            DownloadTransferStatus::DOWNLOADING,
            DownloadTransferStatus::ASSEMBLING,
        ], [
            'status' => DownloadTransferStatus::FAILED,
            'failed_at' => now(),
            'error' => DownloadFailureMessage::normalize($message),
        ]);
        if ($updatedCount === 0) {
            return;
        }

        $updated = DownloadTransferGeneration::fresh($transfer->id, $this->attempt, [DownloadTransferStatus::FAILED]);
        if ($updated) {
            $this->broadcastProgress($updated);
        }

        PumpDomainDownloads::dispatch($transfer->domain);
    }

    private function shouldRetry(Throwable $e): bool
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
            || str_contains($message, 'temporarily unavailable');
    }

    private function scheduleRetry(DownloadTransfer $transfer, string $reason): void
    {
        $delay = max(1, (int) $this->backoff);
        $attempt = max(1, $this->attempts());
        $message = $this->retryMessage($attempt, $delay, $reason);

        $updatedCount = DownloadTransferGeneration::update($transfer->id, $this->attempt, [
            DownloadTransferStatus::QUEUED,
            DownloadTransferStatus::PREPARING,
        ], [
            'status' => DownloadTransferStatus::QUEUED,
            'queued_at' => now(),
            'failed_at' => null,
            'error' => $message,
            'updated_at' => now(),
        ]);
        if ($updatedCount === 0) {
            return;
        }

        $updated = DownloadTransferGeneration::fresh($transfer->id, $this->attempt, [DownloadTransferStatus::QUEUED]);
        if ($updated) {
            try {
                event(new DownloadTransferQueued(DownloadTransferPayload::forQueued($updated)));
            } catch (Throwable) {
                // Broadcast errors shouldn't fail downloads.
            }
            $this->broadcastProgress($updated);
        }

        $this->release($delay);
    }

    private function retryMessage(int $attempt, int $delay, string $reason): string
    {
        $cleanReason = DownloadFailureMessage::normalize($reason, 'Transient network error.');
        $cleanReason = trim(str_replace(["\r", "\n"], ' ', $cleanReason));

        return "Retry {$attempt}/{$this->tries} scheduled in {$delay}s: {$cleanReason}";
    }

    private function requeueWhileYtDlpIsActive(DownloadTransfer $transfer): void
    {
        $requeued = DownloadTransferGeneration::update($transfer->id, $this->attempt, [
            DownloadTransferStatus::QUEUED,
            DownloadTransferStatus::PREPARING,
        ], [
            'status' => DownloadTransferStatus::PENDING,
            'queued_at' => null,
            'updated_at' => now(),
        ]);
        if ($requeued > 0) {
            PumpDomainDownloadsAfterYtDlpRelease::dispatch($transfer->id, $transfer->domain)
                ->delay(now()->addSeconds(5));
        }
    }

    private function broadcastProgress(DownloadTransfer $transfer): void
    {
        try {
            event(new DownloadTransferProgressUpdated(
                DownloadTransferPayload::forProgress($transfer, (int) ($transfer->last_broadcast_percent ?? 0))
            ));
        } catch (Throwable) {
            // Broadcast errors shouldn't fail downloads.
        }
    }
}
