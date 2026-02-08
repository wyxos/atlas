<?php

namespace App\Jobs\Downloads;

use App\Enums\DownloadTransferStatus;
use App\Events\DownloadTransferProgressUpdated;
use App\Models\DownloadTransfer;
use App\Services\Downloads\DownloadTransferPayload;
use App\Services\Downloads\FileDownloadFinalizer;
use App\Services\Downloads\YtDlpCommandBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

class DownloadTransferYtDlp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 60;

    // yt-dlp downloads can take a long time (especially for videos + merging).
    public int $timeout = 1900;

    public function __construct(public int $downloadTransferId)
    {
        $this->onQueue('downloads');
    }

    public function handle(FileDownloadFinalizer $finalizer, YtDlpCommandBuilder $commandBuilder): void
    {
        $transfer = DownloadTransfer::query()->with('file')->find($this->downloadTransferId);
        if (! $transfer || ! $transfer->file) {
            return;
        }

        $file = $transfer->file;

        try {
            if ($transfer->status !== DownloadTransferStatus::DOWNLOADING) {
                return;
            }

            $disk = Storage::disk(config('downloads.disk'));

            $tmpDir = rtrim((string) config('downloads.tmp_dir'), '/').'/transfer-'.$transfer->id;
            if (! $disk->exists($tmpDir)) {
                $disk->makeDirectory($tmpDir, 0755, true);
            }

            $absoluteTmpDir = $disk->path($tmpDir);
            $outputTemplate = $absoluteTmpDir.DIRECTORY_SEPARATOR.'download.%(ext)s';

            $timeoutSeconds = (int) config('downloads.yt_dlp_timeout_seconds', 1800);

            $process = new Process($commandBuilder->build((string) $transfer->url, $outputTemplate));
            $process->setTimeout(max(60, $timeoutSeconds));
            $process->run();

            if (! $process->isSuccessful()) {
                $error = trim($process->getErrorOutput() ?: $process->getOutput());
                $this->failTransfer($transfer, $error !== '' ? $error : 'yt-dlp failed.');

                return;
            }

            $candidates = glob($absoluteTmpDir.DIRECTORY_SEPARATOR.'download.*') ?: [];
            $candidates = array_values(array_filter($candidates, fn ($path) => is_string($path) && is_file($path)));

            if ($candidates === []) {
                $this->failTransfer($transfer, 'yt-dlp completed but no output file was found.');

                return;
            }

            $best = $candidates[0];
            $bestSize = (int) filesize($best);
            foreach ($candidates as $candidate) {
                $size = (int) filesize($candidate);
                if ($size > $bestSize) {
                    $best = $candidate;
                    $bestSize = $size;
                }
            }

            $relativeDownloadedPath = $tmpDir.'/'.basename($best);

            // Finalize moves the file into downloads/ and marks it downloaded.
            $finalizer->finalize($file, $relativeDownloadedPath, null, false);

            $transfer->update([
                'status' => DownloadTransferStatus::PREVIEWING,
                'finished_at' => null,
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
            } catch (Throwable) {
                // Broadcast errors shouldn't fail downloads.
            }

            GenerateTransferPreview::dispatch($transfer->id);

            // Cleanup temp outputs; the finalized file has been moved.
            foreach ($candidates as $candidate) {
                $candidateRel = $tmpDir.'/'.basename($candidate);
                if ($disk->exists($candidateRel)) {
                    $disk->delete($candidateRel);
                }
            }

            if ($disk->exists($tmpDir)) {
                $disk->deleteDirectory($tmpDir);
            }

            PumpDomainDownloads::dispatch((string) $transfer->domain);
        } catch (Throwable $e) {
            $this->failTransfer($transfer, $e->getMessage());
        }
    }

    private function failTransfer(DownloadTransfer $transfer, string $message): void
    {
        DownloadTransfer::query()->whereKey($transfer->id)->update([
            'status' => DownloadTransferStatus::FAILED,
            'failed_at' => now(),
            'error' => $message !== '' ? $message : 'yt-dlp failed.',
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

        PumpDomainDownloads::dispatch((string) $transfer->domain);
    }
}
