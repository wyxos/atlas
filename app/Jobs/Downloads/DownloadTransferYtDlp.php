<?php

namespace App\Jobs\Downloads;

use App\Enums\DownloadTransferStatus;
use App\Events\DownloadTransferProgressUpdated;
use App\Models\DownloadTransfer;
use App\Models\File;
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
        $runtimeCookieJarPath = null;

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
            [$runtimeOptions, $runtimeCookieJarPath] = $this->buildRuntimeOptions($file, $absoluteTmpDir);

            $timeoutSeconds = (int) config('downloads.yt_dlp_timeout_seconds', 1800);
            $lastBroadcastPercent = (int) ($transfer->last_broadcast_percent ?? 0);
            $progressBuffer = '';

            $process = new Process($commandBuilder->build((string) $transfer->url, $outputTemplate, $runtimeOptions));
            $process->setTimeout(max(60, $timeoutSeconds));
            $process->run(function (string $type, string $buffer) use ($transfer, &$lastBroadcastPercent, &$progressBuffer): void {
                $this->broadcastProgressFromChunk($transfer, $type, $buffer, $lastBroadcastPercent, $progressBuffer);
            });
            $this->broadcastProgressFromChunk($transfer, Process::OUT, '', $lastBroadcastPercent, $progressBuffer, true);

            if (! $process->isSuccessful()) {
                $error = trim($process->getErrorOutput() ?: $process->getOutput());
                $this->failTransfer($transfer, $error !== '' ? $error : 'yt-dlp failed.');

                return;
            }

            $currentStatus = DownloadTransfer::query()
                ->whereKey($transfer->id)
                ->value('status');
            if ($currentStatus !== DownloadTransferStatus::DOWNLOADING) {
                // Transfer was canceled/paused/changed while yt-dlp was running; stop here.
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
            File::query()->whereKey($transfer->file_id)->update([
                'download_progress' => 100,
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
        } finally {
            if (is_string($runtimeCookieJarPath) && $runtimeCookieJarPath !== '' && is_file($runtimeCookieJarPath)) {
                @unlink($runtimeCookieJarPath);
            }
        }
    }

    private function failTransfer(DownloadTransfer $transfer, string $message): void
    {
        $normalizedMessage = $this->normalizeFailureMessage($message);

        DownloadTransfer::query()->whereKey($transfer->id)->update([
            'status' => DownloadTransferStatus::FAILED,
            'failed_at' => now(),
            'error' => $normalizedMessage !== '' ? $normalizedMessage : 'yt-dlp failed.',
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

    private function normalizeFailureMessage(string $message): string
    {
        $trimmed = trim($message);
        if ($trimmed === '') {
            return 'yt-dlp failed.';
        }

        $lower = strtolower($trimmed);
        if (
            str_contains($lower, 'no video could be found in this tweet')
            || str_contains($lower, 'requested tweet may only be available for registered users')
        ) {
            return $trimmed.' Ensure the Atlas extension is running on a logged-in page so auth cookies can be attached for this download.';
        }

        return $trimmed;
    }

    private function broadcastProgressFromChunk(
        DownloadTransfer $transfer,
        string $type,
        string $buffer,
        int &$lastBroadcastPercent,
        string &$progressBuffer,
        bool $flush = false
    ): void {
        if (! in_array($type, [Process::OUT, Process::ERR], true)) {
            return;
        }

        if ($buffer !== '') {
            $clean = preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $buffer);
            if (! is_string($clean) || $clean === '') {
                return;
            }

            $progressBuffer .= str_replace("\r", "\n", $clean);
        }

        if ($progressBuffer === '') {
            return;
        }

        $lines = explode("\n", $progressBuffer);
        $progressBuffer = $flush ? '' : (string) array_pop($lines);
        if ($lines === []) {
            return;
        }

        foreach ($lines as $line) {
            if (! preg_match_all('/(\d{1,3}(?:\.\d+)?)\s*%/', $line, $matches)) {
                continue;
            }

            foreach ($matches[1] as $rawPercent) {
                $percent = (int) floor((float) $rawPercent);
                $percent = max(0, min(99, $percent));

                if ($percent <= $lastBroadcastPercent) {
                    continue;
                }

                $this->broadcastProgress($transfer, $percent, $lastBroadcastPercent);
            }
        }
    }

    private function broadcastProgress(DownloadTransfer $transfer, int $percent, int &$lastBroadcastPercent): void
    {
        $updated = DownloadTransfer::query()
            ->whereKey($transfer->id)
            ->where('status', DownloadTransferStatus::DOWNLOADING)
            ->where('last_broadcast_percent', '<', $percent)
            ->update([
                'last_broadcast_percent' => $percent,
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            return;
        }

        File::query()->whereKey($transfer->file_id)->update([
            'download_progress' => $percent,
            'updated_at' => now(),
        ]);

        $lastBroadcastPercent = $percent;
        $transfer->last_broadcast_percent = $percent;

        try {
            event(new DownloadTransferProgressUpdated(
                DownloadTransferPayload::forProgress($transfer, $percent)
            ));
        } catch (Throwable) {
            // Broadcast errors shouldn't fail downloads.
        }
    }

    /**
     * @return array{0: array{cookies_path?: string, user_agent?: string}, 1: string|null}
     */
    private function buildRuntimeOptions(File $file, string $absoluteTmpDir): array
    {
        return [[], null];
    }
}
