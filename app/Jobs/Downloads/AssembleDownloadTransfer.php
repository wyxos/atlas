<?php

namespace App\Jobs\Downloads;

use App\Enums\DownloadTransferStatus;
use App\Events\DownloadTransferProgressUpdated;
use App\Models\DownloadChunk;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Services\Downloads\DownloadTransferPayload;
use App\Services\Downloads\FileDownloadFinalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class AssembleDownloadTransfer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $downloadTransferId,
        public ?string $contentTypeHeader = null
    ) {
        $this->onQueue('downloads');
    }

    /**
     * Execute the job.
     */
    public function handle(FileDownloadFinalizer $finalizer): void
    {
        $transfer = DownloadTransfer::query()->with('file')->find($this->downloadTransferId);
        if (! $transfer || ! $transfer->file) {
            return;
        }

        if ($transfer->status !== DownloadTransferStatus::DOWNLOADING) {
            return;
        }

        $transfer->update([
            'status' => DownloadTransferStatus::ASSEMBLING,
        ]);

        $disk = Storage::disk(config('downloads.disk'));

        $tmpDir = rtrim((string) config('downloads.tmp_dir'), '/').'/transfer-'.$transfer->id;
        $assembledPath = "{$tmpDir}/assembled.tmp";

        if (! $disk->exists($tmpDir)) {
            $disk->makeDirectory($tmpDir, 0755, true);
        }

        $absoluteAssembledPath = $disk->path($assembledPath);
        $out = fopen($absoluteAssembledPath, 'wb');
        if (! $out) {
            $transfer->update([
                'status' => DownloadTransferStatus::FAILED,
                'failed_at' => now(),
                'error' => 'Unable to open assembled output file.',
            ]);

            $transfer->refresh();
            try {
                event(new DownloadTransferProgressUpdated(
                    DownloadTransferPayload::forProgress($transfer, (int) ($transfer->last_broadcast_percent ?? 0))
                ));
            } catch (\Throwable) {
                // Broadcast errors shouldn't fail downloads.
            }

            PumpDomainDownloads::dispatch($transfer->domain);

            return;
        }

        $chunks = DownloadChunk::query()
            ->where('download_transfer_id', $transfer->id)
            ->orderBy('index')
            ->get();

        foreach ($chunks as $chunk) {
            if (empty($chunk->part_path) || ! $disk->exists($chunk->part_path)) {
                fclose($out);

                $transfer->update([
                    'status' => DownloadTransferStatus::FAILED,
                    'failed_at' => now(),
                    'error' => 'Missing chunk part file during assembly.',
                ]);

                $transfer->refresh();
                try {
                    event(new DownloadTransferProgressUpdated(
                        DownloadTransferPayload::forProgress($transfer, (int) ($transfer->last_broadcast_percent ?? 0))
                    ));
                } catch (\Throwable) {
                    // Broadcast errors shouldn't fail downloads.
                }

                PumpDomainDownloads::dispatch($transfer->domain);

                return;
            }

            $absolutePartPath = $disk->path($chunk->part_path);
            $in = fopen($absolutePartPath, 'rb');
            if (! $in) {
                fclose($out);

                $transfer->update([
                    'status' => DownloadTransferStatus::FAILED,
                    'failed_at' => now(),
                    'error' => 'Unable to open chunk part file during assembly.',
                ]);

                $transfer->refresh();
                try {
                    event(new DownloadTransferProgressUpdated(
                        DownloadTransferPayload::forProgress($transfer, (int) ($transfer->last_broadcast_percent ?? 0))
                    ));
                } catch (\Throwable) {
                    // Broadcast errors shouldn't fail downloads.
                }

                PumpDomainDownloads::dispatch($transfer->domain);

                return;
            }

            stream_copy_to_stream($in, $out);
            fclose($in);
        }

        fclose($out);

        $finalizer->finalize($transfer->file, $assembledPath, $this->contentTypeHeader, false);

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

        foreach ($chunks as $chunk) {
            if ($chunk->part_path) {
                $disk->delete($chunk->part_path);
            }
        }

        if ($disk->exists($assembledPath)) {
            $disk->delete($assembledPath);
        }

        if ($disk->exists($tmpDir)) {
            $disk->deleteDirectory($tmpDir);
        }

        PumpDomainDownloads::dispatch($transfer->domain);
    }
}
