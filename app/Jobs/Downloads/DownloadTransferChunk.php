<?php

namespace App\Jobs\Downloads;

use App\Enums\DownloadChunkStatus;
use App\Enums\DownloadTransferStatus;
use App\Events\DownloadTransferProgressUpdated;
use App\Models\DownloadChunk;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Services\Downloads\DownloadFailureMessage;
use App\Services\Downloads\DownloadTransferGeneration;
use App\Services\Downloads\DownloadTransferPayload;
use App\Services\Downloads\DownloadTransferProgressBroadcaster;
use App\Services\Downloads\DownloadTransferRequestOptions;
use App\Services\Downloads\DownloadTransferRuntimeStore;
use App\Services\Downloads\DownloadTransferUrlRefreshService;
use App\Services\Downloads\NativeFallbackMediaValidator;
use App\Services\Downloads\YtDlpUnsupportedUrlFallback;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DownloadTransferChunk implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 600;

    public ?int $attempt = null;

    public function __construct(
        public int $downloadTransferId,
        public int $downloadChunkId,
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
        DownloadTransferProgressBroadcaster $broadcaster,
        ?DownloadTransferRequestOptions $requestOptions = null,
        ?NativeFallbackMediaValidator $mediaValidator = null,
        ?DownloadTransferUrlRefreshService $urlRefreshService = null,
    ): void {
        $requestOptions ??= app(DownloadTransferRequestOptions::class);
        $mediaValidator ??= app(NativeFallbackMediaValidator::class);
        $urlRefreshService ??= app(DownloadTransferUrlRefreshService::class);
        $this->attempt ??= 0;

        $transfer = DownloadTransfer::query()->with('file')->find($this->downloadTransferId);
        if (! DownloadTransferGeneration::matches($transfer, $this->attempt, [DownloadTransferStatus::DOWNLOADING])) {
            return;
        }

        $chunk = null;
        $fh = null;

        try {
            $chunk = DownloadChunk::query()
                ->where('download_transfer_id', $transfer->id)
                ->find($this->downloadChunkId);

            if (! $chunk || empty($chunk->part_path)) {
                return;
            }

            if (! in_array($chunk->status, [DownloadChunkStatus::PENDING, DownloadChunkStatus::DOWNLOADING], true)) {
                return;
            }

            if ($urlRefreshService->refreshBeforeRequest($transfer)) {
                return;
            }

            $chunk->update([
                'status' => DownloadChunkStatus::DOWNLOADING,
                'started_at' => $chunk->started_at ?? now(),
            ]);

            $headers = $requestOptions->httpHeaders($transfer);

            $timeout = (int) config('downloads.http_timeout_seconds');
            $rangeHeader = "bytes={$chunk->range_start}-{$chunk->range_end}";

            $response = Http::timeout($timeout)
                ->withHeaders(array_merge($headers, ['Range' => $rangeHeader]))
                ->withOptions(['stream' => true])
                ->get($transfer->url);

            $transfer = DownloadTransferGeneration::fresh($transfer->id, $this->attempt, [DownloadTransferStatus::DOWNLOADING]);
            if (! $transfer) {
                return;
            }

            if (! $this->isValidRangeResponse($response)) {
                if (in_array($response->status(), [401, 403], true)
                    && $urlRefreshService->refreshAfterUnauthorized($transfer)) {
                    return;
                }

                if ($this->shouldRetryStatus($response->status())) {
                    $this->scheduleRetry($transfer, $chunk, "Received HTTP {$response->status()} for {$rangeHeader}.");

                    return;
                }

                $this->failTransfer($transfer, $chunk, "Invalid range response for {$rangeHeader} (status {$response->status()}).");

                return;
            }

            $nativeRejection = YtDlpUnsupportedUrlFallback::isNativeTransfer($transfer)
                ? $mediaValidator->rejectionForContentType($response->header('Content-Type'))
                : null;
            if ($nativeRejection !== null) {
                $this->failTransfer($transfer, $chunk, $nativeRejection);

                return;
            }

            if ($transfer->error !== null || $transfer->failed_at !== null) {
                $updated = DownloadTransferGeneration::update($transfer->id, $this->attempt, [DownloadTransferStatus::DOWNLOADING], [
                    'failed_at' => null,
                    'error' => null,
                    'updated_at' => now(),
                ]);
                if ($updated === 0) {
                    return;
                }
                $transfer = DownloadTransferGeneration::fresh($transfer->id, $this->attempt, [DownloadTransferStatus::DOWNLOADING]);
                if (! $transfer) {
                    return;
                }
            }

            $disk = Storage::disk(config('downloads.disk'));

            $directory = dirname($chunk->part_path);
            if (! $disk->exists($directory)) {
                $disk->makeDirectory($directory, 0755, true);
            }

            $absolutePartPath = $disk->path($chunk->part_path);
            $fh = fopen($absolutePartPath, 'wb');
            if (! $fh) {
                $this->failTransfer($transfer, $chunk, 'Unable to open part file for writing.');

                return;
            }

            $body = $response->toPsrResponse()->getBody();

            $bufferSize = 1024 * 1024;
            $expectedBytes = ($chunk->range_end - $chunk->range_start) + 1;

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
                    $this->failTransfer($transfer, $chunk, 'Failed writing chunk to disk.');

                    return;
                }

                $bytesWritten += $written;
                $bytesSinceBroadcastCheck += $written;
                $pendingProgressBytes += $written;

                if ($bytesSinceBroadcastCheck >= (2 * 1024 * 1024)) {
                    $bytesSinceBroadcastCheck = 0;
                    $this->flushProgress($transfer->id, $chunk->id, $pendingProgressBytes);
                    if ($this->shouldStop($transfer->id, $chunk, $fh)) {
                        return;
                    }
                    $broadcaster->maybeBroadcast($transfer->id, $this->attempt);
                }
            }

            $this->flushProgress($transfer->id, $chunk->id, $pendingProgressBytes);

            fclose($fh);
            $fh = null;

            if ($bytesWritten !== $expectedBytes) {
                $this->failTransfer($transfer, $chunk, "Chunk incomplete: expected {$expectedBytes} bytes, wrote {$bytesWritten} bytes.");

                return;
            }

            $nativeRejection = YtDlpUnsupportedUrlFallback::isNativeTransfer($transfer)
                ? $mediaValidator->rejectionForArtifact($absolutePartPath, $response->header('Content-Type'))
                : null;
            if ($nativeRejection !== null) {
                $this->failTransfer($transfer, $chunk, $nativeRejection);
                $disk->delete($chunk->part_path);

                return;
            }

            if (! DownloadTransferGeneration::fresh($transfer->id, $this->attempt, [DownloadTransferStatus::DOWNLOADING])) {
                $disk->delete($chunk->part_path);

                return;
            }

            $chunk->update([
                'status' => DownloadChunkStatus::COMPLETED,
                'finished_at' => now(),
            ]);

            $broadcaster->maybeBroadcast($transfer->id, $this->attempt);
        } catch (Throwable $e) {
            if (is_resource($fh)) {
                fclose($fh);
            }

            if ($transfer) {
                if ($this->shouldRetryException($e)) {
                    $this->scheduleRetry($transfer, $chunk, $e->getMessage());

                    return;
                }

                $this->failTransfer($transfer, $chunk, $e->getMessage());
            }
        }
    }

    public function failed(Throwable $e): void
    {
        $this->attempt ??= 0;
        $transfer = DownloadTransfer::query()->find($this->downloadTransferId);
        if (! DownloadTransferGeneration::matches($transfer, $this->attempt, [
            DownloadTransferStatus::DOWNLOADING,
            DownloadTransferStatus::ASSEMBLING,
        ])) {
            return;
        }

        $chunk = DownloadChunk::query()
            ->where('download_transfer_id', $transfer->id)
            ->find($this->downloadChunkId);

        $message = trim($e->getMessage());
        if ($message === '') {
            $message = 'Download chunk failed.';
        }

        $this->failTransfer($transfer, $chunk, $message);
    }

    private function shouldStop(int $transferId, DownloadChunk $chunk, $fh): bool
    {
        $state = DownloadTransfer::query()->whereKey($transferId)->first(['status', 'attempt']);
        if (DownloadTransferGeneration::matches($state, $this->attempt, [DownloadTransferStatus::DOWNLOADING])) {
            return false;
        }

        if (is_resource($fh)) {
            fclose($fh);
        }

        $chunk->update([
            'status' => $state?->status === DownloadTransferStatus::PAUSED
                ? DownloadChunkStatus::PAUSED
                : DownloadChunkStatus::CANCELED,
            'finished_at' => now(),
        ]);

        return true;
    }

    private function isValidRangeResponse(Response $response): bool
    {
        if ($response->status() === 206) {
            return true;
        }

        return false;
    }

    private function flushProgress(int $transferId, int $chunkId, int &$pendingBytes): void
    {
        if ($pendingBytes <= 0) {
            return;
        }

        DB::transaction(function () use ($transferId, $chunkId, $pendingBytes): void {
            $transfer = DownloadTransfer::query()->lockForUpdate()->find($transferId);
            if (! DownloadTransferGeneration::matches($transfer, $this->attempt, [DownloadTransferStatus::DOWNLOADING])) {
                return;
            }

            $transfer->increment('bytes_downloaded', $pendingBytes);
            DownloadChunk::query()->whereKey($chunkId)->increment('bytes_downloaded', $pendingBytes);
        });
        $pendingBytes = 0;
    }

    private function failTransfer(DownloadTransfer $transfer, ?DownloadChunk $chunk, string $message): void
    {
        $failed = DownloadTransferGeneration::runLocked($transfer->id, $this->attempt, [
            DownloadTransferStatus::DOWNLOADING,
            DownloadTransferStatus::ASSEMBLING,
        ], function (DownloadTransfer $current) use ($chunk, $message): void {
            $safeMessage = DownloadFailureMessage::normalize($message);
            if ($chunk) {
                DownloadChunk::query()->whereKey($chunk->id)->update([
                    'status' => DownloadChunkStatus::FAILED,
                    'failed_at' => now(),
                    'error' => $safeMessage,
                ]);
            }
            $current->forceFill([
                'status' => DownloadTransferStatus::FAILED,
                'failed_at' => now(),
                'error' => $safeMessage,
            ])->save();
            app(DownloadTransferRuntimeStore::class)->forgetForTransfer($current->id);
        });
        if (! $failed) {
            return;
        }

        $updated = DownloadTransferGeneration::fresh($transfer->id, $this->attempt, [DownloadTransferStatus::FAILED]);
        if ($updated) {
            try {
                event(new DownloadTransferProgressUpdated(
                    DownloadTransferPayload::forProgress($updated, (int) ($updated->last_broadcast_percent ?? 0))
                ));
            } catch (\Throwable) {
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

    private function scheduleRetry(DownloadTransfer $transfer, ?DownloadChunk $chunk, string $reason): void
    {
        $delay = max(1, (int) $this->backoff);
        $attempt = max(1, $this->attempts());
        $message = $this->retryMessage($attempt, $delay, $reason);
        $scheduled = DownloadTransferGeneration::runLocked(
            $transfer->id,
            $this->attempt,
            [DownloadTransferStatus::DOWNLOADING],
            function (DownloadTransfer $current) use ($chunk, $message): void {
                $chunkBytes = max(0, (int) ($chunk?->bytes_downloaded ?? 0));
                if ($chunkBytes > 0) {
                    $current->forceFill([
                        'bytes_downloaded' => max(0, (int) $current->bytes_downloaded - $chunkBytes),
                    ]);
                }
                $boundary = $this->retryPercentBoundary(
                    max(0, (int) $current->bytes_downloaded),
                    $current->bytes_total,
                );
                $current->forceFill([
                    'last_broadcast_percent' => $boundary,
                    'failed_at' => null,
                    'error' => $message,
                ])->save();
                if ($chunk) {
                    DownloadChunk::query()->whereKey($chunk->id)->update([
                        'status' => DownloadChunkStatus::PENDING,
                        'bytes_downloaded' => 0,
                        'finished_at' => null,
                        'failed_at' => null,
                        'error' => $message,
                    ]);
                }
                File::query()->whereKey($current->file_id)->update([
                    'download_progress' => $boundary,
                    'updated_at' => now(),
                ]);
            },
        );
        if (! $scheduled) {
            return;
        }

        $updated = DownloadTransferGeneration::fresh($transfer->id, $this->attempt, [DownloadTransferStatus::DOWNLOADING]);
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

    private function retryPercentBoundary(int $bytesDownloaded, ?int $bytesTotal): int
    {
        if (! $bytesTotal || $bytesTotal <= 0) {
            return 0;
        }

        $percent = (int) floor(($bytesDownloaded / $bytesTotal) * 100);
        $percent = max(0, min(100, $percent));

        return intdiv($percent, 5) * 5;
    }

    private function retryMessage(int $attempt, int $delay, string $reason): string
    {
        $cleanReason = DownloadFailureMessage::normalize($reason, 'Transient network error.');
        $cleanReason = trim(str_replace(["\r", "\n"], ' ', $cleanReason));

        return "Retry {$attempt}/{$this->tries} scheduled in {$delay}s: {$cleanReason}";
    }
}
