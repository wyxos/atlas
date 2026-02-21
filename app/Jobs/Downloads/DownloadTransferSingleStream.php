<?php

namespace App\Jobs\Downloads;

use App\Enums\DownloadTransferStatus;
use App\Events\DownloadTransferProgressUpdated;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Services\Downloads\DownloadTransferPayload;
use App\Services\Downloads\DownloadTransferProgressBroadcaster;
use App\Services\Downloads\FileDownloadFinalizer;
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

    public function __construct(
        public int $downloadTransferId,
        public ?string $contentTypeHeader = null
    ) {
        $this->onQueue('downloads');
    }

    /**
     * Execute the job.
     */
    public function handle(FileDownloadFinalizer $finalizer, DownloadTransferProgressBroadcaster $broadcaster): void
    {
        $transfer = DownloadTransfer::query()->with('file')->find($this->downloadTransferId);
        if (! $transfer || ! $transfer->file) {
            return;
        }

        $fh = null;

        try {
            if ($transfer->status !== DownloadTransferStatus::DOWNLOADING) {
                return;
            }

            $headers = [];
            if ($transfer->file?->referrer_url) {
                $headers['Referer'] = $transfer->file->referrer_url;
            }

            $timeout = (int) config('downloads.http_timeout_seconds');

            $response = Http::timeout($timeout)->withHeaders($headers)
                ->withOptions(['stream' => true])
                ->get($transfer->url);

            if (! $this->isValidResponse($response)) {
                if ($this->shouldRetryStatus($response->status())) {
                    $this->scheduleRetry($transfer, "Received HTTP {$response->status()} while downloading.");

                    return;
                }

                $this->failTransfer($transfer, "Invalid download response (status {$response->status()}).");

                return;
            }

            if ($transfer->error !== null || $transfer->failed_at !== null) {
                $transfer->update([
                    'failed_at' => null,
                    'error' => null,
                    'updated_at' => now(),
                ]);
                $transfer->refresh();
            }

            if (! $transfer->bytes_total) {
                $contentLength = $response->header('Content-Length');
                $totalBytes = is_numeric($contentLength) && (int) $contentLength > 0 ? (int) $contentLength : null;
                if ($totalBytes) {
                    $transfer->update([
                        'bytes_total' => $totalBytes,
                        'bytes_downloaded' => 0,
                        'last_broadcast_percent' => 0,
                        'updated_at' => now(),
                    ]);
                    if ($transfer->file && (! $transfer->file->size || $transfer->file->size <= 0)) {
                        $transfer->file->update([
                            'size' => $totalBytes,
                            'updated_at' => now(),
                        ]);
                    }
                    $transfer->refresh();
                }
            }
            $disk = Storage::disk(config('downloads.disk'));

            $tmpDir = rtrim((string) config('downloads.tmp_dir'), '/').'/transfer-'.$transfer->id;
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
                    $broadcaster->maybeBroadcast($transfer->id);
                }
            }

            $this->flushProgress($transfer->id, $pendingProgressBytes);

            fclose($fh);
            $fh = null;

            $finalizer->finalize(
                $transfer->file,
                $tmpPath,
                $this->contentTypeHeader ?? $response->header('Content-Type'),
                false
            );

            $transfer->update([
                'status' => DownloadTransferStatus::PREVIEWING,
                'finished_at' => null,
            ]);

            File::query()->whereKey($transfer->file_id)->update([
                'download_progress' => 100,
                'updated_at' => now(),
            ]);

            DownloadTransfer::query()->whereKey($transfer->id)->update([
                'last_broadcast_percent' => 100,
                'updated_at' => now(),
            ]);

            $transfer->refresh();

            try {
                event(new DownloadTransferProgressUpdated(
                    DownloadTransferPayload::forProgress($transfer, 100)
                ));
            } catch (\Throwable) {
                // Broadcast errors shouldn't fail downloads.
            }

            GenerateTransferPreview::dispatch($transfer->id);

            if ($disk->exists($tmpPath)) {
                $disk->delete($tmpPath);
            }

            if ($disk->exists($tmpDir)) {
                $disk->deleteDirectory($tmpDir);
            }

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
        $status = DownloadTransfer::query()->whereKey($transferId)->value('status');
        if ($status === DownloadTransferStatus::DOWNLOADING) {
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

        DownloadTransfer::query()->whereKey($transferId)->increment('bytes_downloaded', $pendingBytes);
        $pendingBytes = 0;
    }

    private function failTransfer(DownloadTransfer $transfer, string $message): void
    {
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
            || str_contains($message, 'temporarily unavailable');
    }

    private function scheduleRetry(DownloadTransfer $transfer, string $reason): void
    {
        $delay = max(1, (int) $this->backoff);
        $attempt = max(1, $this->attempts());
        $message = $this->retryMessage($attempt, $delay, $reason);

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

    // Progress broadcasting is handled by DownloadTransferProgressBroadcaster.
}
