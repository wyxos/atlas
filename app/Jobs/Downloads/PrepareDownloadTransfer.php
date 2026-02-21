<?php

namespace App\Jobs\Downloads;

use App\Enums\DownloadChunkStatus;
use App\Enums\DownloadTransferStatus;
use App\Events\DownloadTransferProgressUpdated;
use App\Events\DownloadTransferQueued;
use App\Models\DownloadChunk;
use App\Models\DownloadTransfer;
use App\Services\Downloads\DownloadTransferPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

use function data_get;

class PrepareDownloadTransfer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public int $downloadTransferId)
    {
        $this->onQueue('downloads');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $transfer = DownloadTransfer::query()->with('file')->find($this->downloadTransferId);
        if (! $transfer || ! $transfer->file) {
            return;
        }

        try {
            if (! in_array($transfer->status, [DownloadTransferStatus::QUEUED, DownloadTransferStatus::PREPARING], true)) {
                return;
            }

            if (data_get($transfer->file->listing_metadata, 'download_via') === 'yt-dlp') {
                $transfer->update([
                    'bytes_total' => null,
                    'bytes_downloaded' => 0,
                    'last_broadcast_percent' => 0,
                    'status' => DownloadTransferStatus::DOWNLOADING,
                    'started_at' => $transfer->started_at ?? now(),
                    'failed_at' => null,
                    'error' => null,
                ]);

                $transfer->refresh();
                try {
                    event(new DownloadTransferProgressUpdated(
                        DownloadTransferPayload::forProgress($transfer, (int) ($transfer->last_broadcast_percent ?? 0))
                    ));
                } catch (Throwable) {
                    // Broadcast errors shouldn't fail downloads.
                }

                DownloadTransferYtDlp::dispatch($transfer->id);

                return;
            }

            $transfer->update([
                'status' => DownloadTransferStatus::PREPARING,
                'failed_at' => null,
                'error' => null,
            ]);

            $transfer->refresh();
            try {
                event(new DownloadTransferProgressUpdated(
                    DownloadTransferPayload::forProgress($transfer, (int) ($transfer->last_broadcast_percent ?? 0))
                ));
            } catch (Throwable) {
                // Broadcast errors shouldn't fail downloads.
            }

            $url = $transfer->url;
            $headers = [];
            if ($transfer->file?->referrer_url) {
                $headers['Referer'] = $transfer->file->referrer_url;
            }
            $timeout = (int) config('downloads.http_timeout_seconds');

            $head = Http::timeout($timeout)->withHeaders($headers)->head($url);
            $contentType = $head->header('Content-Type');
            $contentLength = $head->header('Content-Length');
            $acceptRanges = $head->header('Accept-Ranges');

            $tagName = data_get($transfer->file->listing_metadata, 'tag_name');
            if (is_string($contentType)) {
                $mime = strtolower(trim(explode(';', $contentType, 2)[0]));

                // Some "video" URLs are actually HTML pages (e.g. YouTube embeds). For videos/iframes, prefer
                // yt-dlp so we don't "download" HTML and mark it as completed.
                if (in_array($tagName, ['video', 'iframe'], true) && in_array($mime, ['text/html', 'application/xhtml+xml'], true)) {
                    try {
                        $metadata = $transfer->file->listing_metadata;
                        if ($metadata instanceof \Illuminate\Support\Collection) {
                            $metadata = $metadata->all();
                        }
                        if (! is_array($metadata)) {
                            $metadata = [];
                        }

                        $metadata['download_via'] = 'yt-dlp';
                        $metadata['download_via_reason'] = 'content-type-html';

                        $transfer->file->forceFill([
                            'listing_metadata' => $metadata,
                        ])->save();
                    } catch (Throwable) {
                        // Metadata updates shouldn't block the download fallback.
                    }

                    // If the extension provided a page URL, prefer it for yt-dlp (embed URLs frequently return HTML).
                    $pageUrl = data_get($transfer->file->listing_metadata, 'page_url');
                    if (is_string($pageUrl) && $pageUrl !== '') {
                        $transfer->update([
                            'url' => $pageUrl,
                            'updated_at' => now(),
                        ]);
                    }

                    $transfer->update([
                        'bytes_total' => null,
                        'bytes_downloaded' => 0,
                        'last_broadcast_percent' => 0,
                        'status' => DownloadTransferStatus::DOWNLOADING,
                        'started_at' => $transfer->started_at ?? now(),
                        'failed_at' => null,
                        'error' => null,
                    ]);

                    $transfer->refresh();
                    try {
                        event(new DownloadTransferProgressUpdated(
                            DownloadTransferPayload::forProgress($transfer, (int) ($transfer->last_broadcast_percent ?? 0))
                        ));
                    } catch (Throwable) {
                        // Broadcast errors shouldn't fail downloads.
                    }

                    DownloadTransferYtDlp::dispatch($transfer->id);

                    return;
                }
            }

            $totalBytes = is_numeric($contentLength) && (int) $contentLength > 0 ? (int) $contentLength : null;
            $rangesSupported = is_string($acceptRanges) && str_contains(strtolower($acceptRanges), 'bytes');

            if (! $rangesSupported || $totalBytes === null) {
                $rangeProbe = Http::timeout($timeout)->withHeaders(array_merge($headers, ['Range' => 'bytes=0-0']))->get($url);
                if ($rangeProbe->status() === 206) {
                    $rangesSupported = true;
                    $contentType = $contentType ?? $rangeProbe->header('Content-Type');
                    $totalBytes = $totalBytes ?? $this->parseTotalBytesFromContentRange($rangeProbe->header('Content-Range'));
                }
            }

            if ($totalBytes === null) {
                $transfer->update([
                    'bytes_total' => null,
                    'bytes_downloaded' => 0,
                    'last_broadcast_percent' => 0,
                    'status' => DownloadTransferStatus::DOWNLOADING,
                    'started_at' => $transfer->started_at ?? now(),
                    'failed_at' => null,
                    'error' => null,
                ]);

                $transfer->refresh();
                try {
                    event(new DownloadTransferProgressUpdated(
                        DownloadTransferPayload::forProgress($transfer, (int) ($transfer->last_broadcast_percent ?? 0))
                    ));
                } catch (Throwable) {
                    // Broadcast errors shouldn't fail downloads.
                }

                DownloadTransferSingleStream::dispatch($transfer->id, $contentType);

                return;
            }

            if ($transfer->file && (! $transfer->file->size || $transfer->file->size <= 0)) {
                $transfer->file->update([
                    'size' => $totalBytes,
                    'updated_at' => now(),
                ]);
            }
            $transfer->update([
                'bytes_total' => $totalBytes,
                'bytes_downloaded' => 0,
                'last_broadcast_percent' => 0,
                'status' => DownloadTransferStatus::DOWNLOADING,
                'started_at' => $transfer->started_at ?? now(),
                'failed_at' => null,
                'error' => null,
            ]);

            $transfer->refresh();
            try {
                event(new DownloadTransferProgressUpdated(
                    DownloadTransferPayload::forProgress($transfer, (int) ($transfer->last_broadcast_percent ?? 0))
                ));
            } catch (Throwable) {
                // Broadcast errors shouldn't fail downloads.
            }

            if (! $rangesSupported || $totalBytes < (int) config('downloads.min_bytes_for_chunking')) {
                DownloadTransferSingleStream::dispatch($transfer->id, $contentType);

                return;
            }

            $chunkCount = max(1, (int) config('downloads.chunk_count'));
            $chunkSize = (int) ceil($totalBytes / $chunkCount);

            $disk = Storage::disk(config('downloads.disk'));
            $tmpDir = rtrim((string) config('downloads.tmp_dir'), '/').'/transfer-'.$transfer->id;
            if (! $disk->exists($tmpDir)) {
                $disk->makeDirectory($tmpDir, 0755, true);
            }

            $chunkIds = [];

            for ($i = 0; $i < $chunkCount; $i++) {
                $start = $i * $chunkSize;
                if ($start >= $totalBytes) {
                    break;
                }

                $end = min($totalBytes - 1, (($i + 1) * $chunkSize) - 1);
                $partPath = "{$tmpDir}/part-{$i}.part";

                $chunk = DownloadChunk::query()->create([
                    'download_transfer_id' => $transfer->id,
                    'index' => $i,
                    'range_start' => $start,
                    'range_end' => $end,
                    'bytes_downloaded' => 0,
                    'status' => DownloadChunkStatus::PENDING,
                    'part_path' => $partPath,
                ]);

                $chunkIds[] = $chunk->id;
            }

            $jobs = array_map(fn (int $chunkId) => new DownloadTransferChunk($transfer->id, $chunkId, $contentType), $chunkIds);

            $batch = Bus::batch($jobs)
                ->then(fn () => AssembleDownloadTransfer::dispatch($transfer->id, $contentType))
                ->catch(function (Throwable $e) use ($transfer) {
                    DownloadTransfer::query()->whereKey($transfer->id)->update([
                        'status' => DownloadTransferStatus::FAILED,
                        'failed_at' => now(),
                        'error' => $e->getMessage(),
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
                })
                ->dispatch();

            $transfer->update([
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

        // Example: "bytes 0-0/12345"
        $parts = explode('/', $contentRange);
        if (count($parts) !== 2) {
            return null;
        }

        $total = trim($parts[1]);

        return is_numeric($total) ? (int) $total : null;
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
        $delay = max(1, $this->retryDelaySeconds());
        $attempt = max(1, $this->attempts());
        $message = $this->retryMessage($attempt, $delay, $reason);

        DownloadTransfer::query()->whereKey($transfer->id)->update([
            'status' => DownloadTransferStatus::QUEUED,
            'queued_at' => now(),
            'failed_at' => null,
            'error' => $message,
            'updated_at' => now(),
        ]);

        $updated = DownloadTransfer::query()->with('file')->find($transfer->id);
        if ($updated) {
            try {
                event(new DownloadTransferQueued(DownloadTransferPayload::forQueued($updated)));
                event(new DownloadTransferProgressUpdated(
                    DownloadTransferPayload::forProgress($updated, (int) ($updated->last_broadcast_percent ?? 0))
                ));
            } catch (Throwable) {
                // Broadcast errors shouldn't fail downloads.
            }
        }

        $this->release($delay);
    }

    private function retryDelaySeconds(): int
    {
        return (int) $this->backoff;
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
