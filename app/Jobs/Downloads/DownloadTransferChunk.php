<?php

namespace App\Jobs\Downloads;

use App\Enums\DownloadChunkStatus;
use App\Enums\DownloadTransferStatus;
use App\Events\DownloadTransferProgressUpdated;
use App\Models\DownloadChunk;
use App\Models\DownloadTransfer;
use App\Services\Downloads\DownloadTransferPayload;
use App\Services\Downloads\DownloadTransferProgressBroadcaster;
use Illuminate\Bus\Batchable;
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

class DownloadTransferChunk implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public int $downloadTransferId,
        public int $downloadChunkId,
        public ?string $contentTypeHeader = null
    ) {
        $this->onQueue('downloads');
    }

    /**
     * Execute the job.
     */
    public function handle(DownloadTransferProgressBroadcaster $broadcaster): void
    {
        $transfer = DownloadTransfer::query()->with('file')->find($this->downloadTransferId);
        if (! $transfer || $transfer->status !== DownloadTransferStatus::DOWNLOADING) {
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

            $chunk->update([
                'status' => DownloadChunkStatus::DOWNLOADING,
                'started_at' => $chunk->started_at ?? now(),
            ]);

            $headers = [];
            if ($transfer->file?->referrer_url) {
                $headers['Referer'] = $transfer->file->referrer_url;
            }

            $timeout = (int) config('downloads.http_timeout_seconds');
            $rangeHeader = "bytes={$chunk->range_start}-{$chunk->range_end}";

            $response = Http::timeout($timeout)
                ->withHeaders(array_merge($headers, ['Range' => $rangeHeader]))
                ->withOptions(['stream' => true])
                ->get($transfer->url);

            if (! $this->isValidRangeResponse($response)) {
                if ($this->shouldRetryStatus($response->status())) {
                    $this->scheduleRetry($transfer, $chunk, "Received HTTP {$response->status()} for {$rangeHeader}.");

                    return;
                }

                $this->failTransfer($transfer, $chunk, "Invalid range response for {$rangeHeader} (status {$response->status()}).");

                return;
            }

            if ($transfer->error !== null || $transfer->failed_at !== null) {
                DownloadTransfer::query()->whereKey($transfer->id)->update([
                    'failed_at' => null,
                    'error' => null,
                    'updated_at' => now(),
                ]);
                $transfer->refresh();
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
                    $broadcaster->maybeBroadcast($transfer->id);
                }
            }

            $this->flushProgress($transfer->id, $chunk->id, $pendingProgressBytes);

            fclose($fh);
            $fh = null;

            if ($bytesWritten !== $expectedBytes) {
                $this->failTransfer($transfer, $chunk, "Chunk incomplete: expected {$expectedBytes} bytes, wrote {$bytesWritten} bytes.");

                return;
            }

            $chunk->update([
                'status' => DownloadChunkStatus::COMPLETED,
                'finished_at' => now(),
            ]);

            $broadcaster->maybeBroadcast($transfer->id);
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

    private function shouldStop(int $transferId, DownloadChunk $chunk, $fh): bool
    {
        $status = DownloadTransfer::query()->whereKey($transferId)->value('status');
        if ($status === DownloadTransferStatus::DOWNLOADING) {
            return false;
        }

        if (is_resource($fh)) {
            fclose($fh);
        }

        $chunk->update([
            'status' => $status === DownloadTransferStatus::PAUSED
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

        DownloadTransfer::query()->whereKey($transferId)->increment('bytes_downloaded', $pendingBytes);
        DownloadChunk::query()->whereKey($chunkId)->increment('bytes_downloaded', $pendingBytes);
        $pendingBytes = 0;
    }

    private function failTransfer(DownloadTransfer $transfer, ?DownloadChunk $chunk, string $message): void
    {
        if ($chunk) {
            $chunk->update([
                'status' => DownloadChunkStatus::FAILED,
                'failed_at' => now(),
                'error' => $message,
            ]);
        }

        DownloadTransfer::query()->whereKey($transfer->id)->update([
            'status' => DownloadTransferStatus::FAILED,
            'failed_at' => now(),
            'error' => $message,
        ]);

        $updated = DownloadTransfer::query()->find($transfer->id);
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
            || str_contains($message, 'temporarily unavailable');
    }

    private function scheduleRetry(DownloadTransfer $transfer, ?DownloadChunk $chunk, string $reason): void
    {
        $delay = max(1, (int) $this->backoff);
        $attempt = max(1, $this->attempts());
        $message = $this->retryMessage($attempt, $delay, $reason);

        if ($chunk) {
            DownloadChunk::query()->whereKey($chunk->id)->update([
                'status' => DownloadChunkStatus::PENDING,
                'failed_at' => null,
                'error' => $message,
                'updated_at' => now(),
            ]);
        }

        DownloadTransfer::query()->whereKey($transfer->id)->update([
            'failed_at' => null,
            'error' => $message,
            'updated_at' => now(),
        ]);

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
        $cleanReason = trim(str_replace(["\r", "\n"], ' ', $reason));
        if ($cleanReason === '') {
            $cleanReason = 'Transient network error.';
        }

        return "Retry {$attempt}/{$this->tries} scheduled in {$delay}s: {$cleanReason}";
    }
}
