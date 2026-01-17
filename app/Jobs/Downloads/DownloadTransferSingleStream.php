<?php

namespace App\Jobs\Downloads;

use App\Enums\DownloadTransferStatus;
use App\Events\DownloadTransferProgressUpdated;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Services\Downloads\DownloadTransferProgressBroadcaster;
use App\Services\Downloads\FileDownloadFinalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class DownloadTransferSingleStream implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public int $downloadTransferId,
        public ?string $contentTypeHeader = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(FileDownloadFinalizer $finalizer, DownloadTransferProgressBroadcaster $broadcaster): void
    {
        $transfer = DownloadTransfer::query()->with('file')->find($this->downloadTransferId);
        if (! $transfer || ! $transfer->file) {
            return;
        }

        if ($transfer->status !== DownloadTransferStatus::DOWNLOADING) {
            return;
        }

        if (! $transfer->bytes_total) {
            $transfer->update([
                'status' => DownloadTransferStatus::FAILED,
                'failed_at' => now(),
                'error' => 'bytes_total missing for single-stream download.',
            ]);

            PumpDomainDownloads::dispatch($transfer->domain);

            return;
        }

        $timeout = (int) config('downloads.http_timeout_seconds');

        $response = Http::timeout($timeout)
            ->withOptions(['stream' => true])
            ->get($transfer->url);

        if (! $this->isValidResponse($response)) {
            $transfer->update([
                'status' => DownloadTransferStatus::FAILED,
                'failed_at' => now(),
                'error' => "Invalid download response (status {$response->status()}).",
            ]);

            PumpDomainDownloads::dispatch($transfer->domain);

            return;
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
            $transfer->update([
                'status' => DownloadTransferStatus::FAILED,
                'failed_at' => now(),
                'error' => 'Unable to open temp file for writing.',
            ]);

            PumpDomainDownloads::dispatch($transfer->domain);

            return;
        }

        $body = $response->toPsrResponse()->getBody();
        $bufferSize = 1024 * 1024;

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
                $transfer->update([
                    'status' => DownloadTransferStatus::FAILED,
                    'failed_at' => now(),
                    'error' => 'Failed writing download to disk.',
                ]);

                PumpDomainDownloads::dispatch($transfer->domain);

                return;
            }

            $bytesWritten += $written;
            $bytesSinceBroadcastCheck += $written;

            DownloadTransfer::query()->whereKey($transfer->id)->increment('bytes_downloaded', $written);

            if ($bytesSinceBroadcastCheck >= (2 * 1024 * 1024)) {
                $bytesSinceBroadcastCheck = 0;
                if ($this->shouldStop($transfer->id, $fh)) {
                    return;
                }
                $broadcaster->maybeBroadcast($transfer->id);
            }
        }

        fclose($fh);

        $finalizer->finalize($transfer->file, $tmpPath, $this->contentTypeHeader ?? $response->header('Content-Type'));

        $transfer->update([
            'status' => DownloadTransferStatus::COMPLETED,
            'finished_at' => now(),
        ]);

        File::query()->whereKey($transfer->file_id)->update([
            'download_progress' => 100,
            'updated_at' => now(),
        ]);

        DownloadTransfer::query()->whereKey($transfer->id)->update([
            'last_broadcast_percent' => 100,
            'updated_at' => now(),
        ]);

        try {
            event(new DownloadTransferProgressUpdated(
                downloadTransferId: $transfer->id,
                fileId: $transfer->file_id,
                domain: $transfer->domain,
                status: DownloadTransferStatus::COMPLETED,
                percent: 100
            ));
        } catch (\Throwable) {
            // Broadcast errors shouldn't fail downloads.
        }

        if ($disk->exists($tmpPath)) {
            $disk->delete($tmpPath);
        }

        if ($disk->exists($tmpDir)) {
            $disk->deleteDirectory($tmpDir);
        }

        PumpDomainDownloads::dispatch($transfer->domain);
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

    // Progress broadcasting is handled by DownloadTransferProgressBroadcaster.
}
