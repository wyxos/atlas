<?php

namespace App\Jobs\Downloads;

use App\Enums\DownloadChunkStatus;
use App\Enums\DownloadTransferStatus;
use App\Models\DownloadChunk;
use App\Models\DownloadTransfer;
use App\Services\Downloads\DownloadTransferProgressBroadcaster;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class DownloadTransferChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public int $downloadTransferId,
        public int $downloadChunkId,
        public ?string $contentTypeHeader = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(DownloadTransferProgressBroadcaster $broadcaster): void
    {
        $transfer = DownloadTransfer::query()->find($this->downloadTransferId);
        if (! $transfer || $transfer->status !== DownloadTransferStatus::DOWNLOADING) {
            return;
        }

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

        $timeout = (int) config('downloads.http_timeout_seconds');
        $rangeHeader = "bytes={$chunk->range_start}-{$chunk->range_end}";

        $response = Http::timeout($timeout)
            ->withHeaders(['Range' => $rangeHeader])
            ->withOptions(['stream' => true])
            ->get($transfer->url);

        if (! $this->isValidRangeResponse($response)) {
            $this->failTransfer($transfer, $chunk, "Invalid range response for {$rangeHeader} (status {$response->status()}).");

            return;
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

            DownloadTransfer::query()->whereKey($transfer->id)->increment('bytes_downloaded', $written);
            DownloadChunk::query()->whereKey($chunk->id)->increment('bytes_downloaded', $written);

            if ($bytesSinceBroadcastCheck >= (2 * 1024 * 1024)) {
                $bytesSinceBroadcastCheck = 0;
                if ($this->shouldStop($transfer->id, $chunk, $fh)) {
                    return;
                }
                $broadcaster->maybeBroadcast($transfer->id);
            }
        }

        fclose($fh);

        if ($bytesWritten !== $expectedBytes) {
            $this->failTransfer($transfer, $chunk, "Chunk incomplete: expected {$expectedBytes} bytes, wrote {$bytesWritten} bytes.");

            return;
        }

        $chunk->update([
            'status' => DownloadChunkStatus::COMPLETED,
            'finished_at' => now(),
        ]);

        $broadcaster->maybeBroadcast($transfer->id);
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

    private function failTransfer(DownloadTransfer $transfer, DownloadChunk $chunk, string $message): void
    {
        $chunk->update([
            'status' => DownloadChunkStatus::FAILED,
            'failed_at' => now(),
            'error' => $message,
        ]);

        DownloadTransfer::query()->whereKey($transfer->id)->update([
            'status' => DownloadTransferStatus::FAILED,
            'failed_at' => now(),
            'error' => $message,
        ]);

        PumpDomainDownloads::dispatch($transfer->domain);
    }
}
