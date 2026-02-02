<?php

namespace App\Jobs\Downloads;

use App\Enums\DownloadChunkStatus;
use App\Enums\DownloadTransferStatus;
use App\Events\DownloadTransferProgressUpdated;
use App\Models\DownloadChunk;
use App\Models\DownloadTransfer;
use App\Services\Downloads\DownloadTransferPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

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

        if (! in_array($transfer->status, [DownloadTransferStatus::QUEUED, DownloadTransferStatus::PREPARING], true)) {
            return;
        }

        $transfer->update([
            'status' => DownloadTransferStatus::PREPARING,
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
}
