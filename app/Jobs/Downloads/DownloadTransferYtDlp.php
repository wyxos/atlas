<?php

namespace App\Jobs\Downloads;

use App\Enums\DownloadTransferStatus;
use App\Events\DownloadTransferProgressUpdated;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Services\Downloads\DownloadTransferExecutionLock;
use App\Services\Downloads\DownloadTransferPayload;
use App\Services\Downloads\DownloadTransferRequestOptions;
use App\Services\Downloads\DownloadTransferRuntimeStore;
use App\Services\Downloads\DownloadTransferTempDirectory;
use App\Services\Downloads\FileDownloadFinalizer;
use App\Services\Downloads\YtDlpCommandBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Lock;
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

    public function __construct(public int $downloadTransferId, public int $attempt = 0)
    {
        $this->onQueue('downloads');
    }

    public function handle(
        FileDownloadFinalizer $finalizer,
        YtDlpCommandBuilder $commandBuilder,
        ?DownloadTransferRequestOptions $requestOptions = null,
        ?DownloadTransferExecutionLock $executionLock = null,
        ?DownloadTransferTempDirectory $tempDirectory = null
    ): void {
        $requestOptions ??= app(DownloadTransferRequestOptions::class);
        $executionLock ??= app(DownloadTransferExecutionLock::class);
        $tempDirectory ??= app(DownloadTransferTempDirectory::class);

        $transfer = DownloadTransfer::query()->with('file')->find($this->downloadTransferId);
        if (! $transfer || ! $transfer->file) {
            return;
        }

        if (! $this->matchesAttempt($transfer)) {
            return;
        }

        $file = $transfer->file;
        $runtimeCookieJarPath = null;
        $lock = null;

        try {
            if ($transfer->status !== DownloadTransferStatus::DOWNLOADING) {
                return;
            }

            $timeoutSeconds = (int) config('downloads.yt_dlp_timeout_seconds', 1800);
            $lock = $executionLock->acquireYtDlp($transfer->id, $timeoutSeconds + 60);
            if (! $lock) {
                return;
            }

            $disk = Storage::disk(config('downloads.disk'));

            $tmpDir = $tempDirectory->ytDlpAttempt($transfer->id, $this->attempt);
            if (! $disk->exists($tmpDir)) {
                $disk->makeDirectory($tmpDir, 0755, true);
            }

            $absoluteTmpDir = $disk->path($tmpDir);
            $outputTemplate = $absoluteTmpDir.DIRECTORY_SEPARATOR.'download.%(ext)s';
            [$runtimeOptions, $runtimeCookieJarPath] = $this->buildRuntimeOptions($transfer, $absoluteTmpDir, $requestOptions);

            $lastBroadcastPercent = (int) ($transfer->last_broadcast_percent ?? 0);
            $progressBuffer = '';
            $aborted = false;

            $process = new Process($commandBuilder->build((string) $transfer->url, $outputTemplate, $runtimeOptions));
            $process->setTimeout(max(60, $timeoutSeconds));
            $process->start(function (string $type, string $buffer) use ($transfer, &$lastBroadcastPercent, &$progressBuffer): void {
                $this->broadcastProgressFromChunk($transfer, $type, $buffer, $lastBroadcastPercent, $progressBuffer);
            });

            while ($process->isRunning()) {
                if (! $this->shouldKeepRunning($transfer->id)) {
                    $aborted = true;
                    $process->stop(1);
                    break;
                }

                usleep(250000);
            }

            if (! $aborted) {
                $process->wait();
            }

            $this->broadcastProgressFromChunk($transfer, Process::OUT, '', $lastBroadcastPercent, $progressBuffer, true);

            if ($aborted) {
                $this->cleanupTempArtifacts($transfer->id, $this->attempt);
                PumpDomainDownloads::dispatch((string) $transfer->domain);

                return;
            }

            if (! $process->isSuccessful()) {
                $error = trim($process->getErrorOutput() ?: $process->getOutput());
                $this->failTransfer(
                    $transfer,
                    $error !== '' ? $error : 'yt-dlp failed.',
                    cleanupTempArtifacts: $this->shouldDiscardTempArtifacts($error)
                );

                return;
            }

            $currentTransfer = DownloadTransfer::query()
                ->whereKey($transfer->id)
                ->first(['status', 'attempt']);
            if (! $currentTransfer || $currentTransfer->status !== DownloadTransferStatus::DOWNLOADING || (int) $currentTransfer->attempt !== $this->attempt) {
                $this->cleanupTempArtifacts($transfer->id, $this->attempt);
                PumpDomainDownloads::dispatch((string) $transfer->domain);

                return;
            }

            $candidates = $this->finalizedOutputCandidates($absoluteTmpDir);

            if ($candidates === []) {
                $this->failTransfer($transfer, 'yt-dlp completed without a finalized output file.', cleanupTempArtifacts: true);

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

            if ($bestSize <= 0) {
                $this->failTransfer($transfer, 'yt-dlp produced an empty output file.', cleanupTempArtifacts: true);

                return;
            }

            $relativeDownloadedPath = $tmpDir.'/'.basename($best);

            // Finalize moves the file into downloads/ and marks it downloaded.
            $finalizer->finalize($file, $relativeDownloadedPath, null, false);

            $updated = DownloadTransfer::query()->whereKey($transfer->id)
                ->where('attempt', $this->attempt)
                ->update([
                    'status' => DownloadTransferStatus::PREVIEWING,
                    'finished_at' => null,
                    'failed_at' => null,
                    'error' => null,
                ]);
            if ($updated === 0) {
                $this->cleanupTempArtifacts($transfer->id, $this->attempt);
                PumpDomainDownloads::dispatch((string) $transfer->domain);

                return;
            }
            app(DownloadTransferRuntimeStore::class)->forgetForTransfer($transfer->id);

            DownloadTransfer::query()->whereKey($transfer->id)->where('attempt', $this->attempt)->update([
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
            $this->failTransfer(
                $transfer,
                $e->getMessage(),
                cleanupTempArtifacts: $this->shouldDiscardTempArtifacts($e->getMessage())
            );
        } finally {
            if ($lock instanceof Lock) {
                $lock->release();
            }

            if (is_string($runtimeCookieJarPath) && $runtimeCookieJarPath !== '' && is_file($runtimeCookieJarPath)) {
                @unlink($runtimeCookieJarPath);
            }
        }
    }

    private function failTransfer(DownloadTransfer $transfer, string $message, bool $cleanupTempArtifacts = false): void
    {
        $normalizedMessage = $this->normalizeFailureMessage($message);

        if ($cleanupTempArtifacts) {
            $this->cleanupTempArtifacts($transfer->id, $this->attempt);
            if (! str_contains($normalizedMessage, 'Use Restart to fetch the file from scratch.')) {
                $normalizedMessage .= ' Atlas discarded the temporary yt-dlp fragments for this transfer. Use Restart to fetch the file from scratch.';
            }
        }

        $updatedCount = DownloadTransfer::query()->whereKey($transfer->id)
            ->where('attempt', $this->attempt)
            ->update([
                'status' => DownloadTransferStatus::FAILED,
                'failed_at' => now(),
                'error' => $normalizedMessage !== '' ? $normalizedMessage : 'yt-dlp failed.',
            ]);
        if ($updatedCount === 0) {
            PumpDomainDownloads::dispatch((string) $transfer->domain);

            return;
        }
        app(DownloadTransferRuntimeStore::class)->forgetForTransfer($transfer->id);

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

        if ($this->shouldDiscardTempArtifacts($trimmed)) {
            return $trimmed.' Atlas discarded the temporary yt-dlp fragments for this transfer. Use Restart to fetch the file from scratch.';
        }

        return $trimmed;
    }

    /**
     * @return list<string>
     */
    private function finalizedOutputCandidates(string $absoluteTmpDir): array
    {
        $candidates = glob($absoluteTmpDir.DIRECTORY_SEPARATOR.'download.*') ?: [];

        return array_values(array_filter($candidates, function ($path): bool {
            if (! is_string($path) || ! is_file($path)) {
                return false;
            }

            $lower = strtolower($path);

            return ! str_ends_with($lower, '.part')
                && ! str_ends_with($lower, '.ytdl')
                && ! str_ends_with($lower, '.tmp');
        }));
    }

    private function shouldDiscardTempArtifacts(string $message): bool
    {
        $lower = strtolower(trim($message));
        if ($lower === '') {
            return false;
        }

        return str_contains($lower, '.ytdl file is corrupt')
            || str_contains($lower, 'downloaded file is empty')
            || (
                str_contains($lower, 'unable to rename file')
                && str_contains($lower, '.part-frag')
            );
    }

    private function cleanupTempArtifacts(int $transferId, int $attempt): void
    {
        $disk = Storage::disk(config('downloads.disk'));
        $tmpDir = app(DownloadTransferTempDirectory::class)->ytDlpAttempt($transferId, $attempt);

        if ($disk->exists($tmpDir)) {
            $disk->deleteDirectory($tmpDir);
        }
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
            ->where('attempt', $this->attempt)
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
    private function buildRuntimeOptions(
        DownloadTransfer $transfer,
        string $absoluteTmpDir,
        DownloadTransferRequestOptions $requestOptions
    ): array {
        return $requestOptions->ytDlpRuntimeOptions($transfer, $absoluteTmpDir);
    }

    private function matchesAttempt(DownloadTransfer $transfer): bool
    {
        return (int) ($transfer->attempt ?? 0) === $this->attempt;
    }

    private function shouldKeepRunning(int $transferId): bool
    {
        $state = DownloadTransfer::query()
            ->whereKey($transferId)
            ->first(['status', 'attempt']);

        return $state
            && $state->status === DownloadTransferStatus::DOWNLOADING
            && (int) $state->attempt === $this->attempt;
    }
}
