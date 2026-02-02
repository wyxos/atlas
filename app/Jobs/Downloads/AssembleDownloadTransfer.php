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
use Throwable;

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

        $out = null;

        try {
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
                $this->failTransfer($transfer, 'Unable to open assembled output file.');

                return;
            }

            $chunks = DownloadChunk::query()
                ->where('download_transfer_id', $transfer->id)
                ->orderBy('index')
                ->get();

            foreach ($chunks as $chunk) {
                if (empty($chunk->part_path) || ! $disk->exists($chunk->part_path)) {
                    fclose($out);
                    $out = null;
                    $this->failTransfer($transfer, 'Missing chunk part file during assembly.');

                    return;
                }

                $absolutePartPath = $disk->path($chunk->part_path);
                $in = fopen($absolutePartPath, 'rb');
                if (! $in) {
                    fclose($out);
                    $out = null;
                    $this->failTransfer($transfer, 'Unable to open chunk part file during assembly.');

                    return;
                }

                stream_copy_to_stream($in, $out);
                fclose($in);
            }

            fclose($out);
            $out = null;

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
        } catch (Throwable $e) {
            if (is_resource($out)) {
                fclose($out);
            }

            $this->failTransfer($transfer, $e->getMessage());
        }
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
}
