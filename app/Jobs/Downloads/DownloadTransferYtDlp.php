<?php

namespace App\Jobs\Downloads;

use App\Enums\DownloadTransferStatus;
use App\Events\DownloadTransferProgressUpdated;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Services\Downloads\DownloadTransferPayload;
use App\Services\Downloads\FileDownloadFinalizer;
use App\Services\Downloads\YtDlpCommandBuilder;
use App\Support\ExtensionAuthContext;
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
        $shouldClearAuthContext = false;
        $runtimeCookieJarPath = null;

        try {
            if ($transfer->status !== DownloadTransferStatus::DOWNLOADING) {
                return;
            }
            $shouldClearAuthContext = true;

            $disk = Storage::disk(config('downloads.disk'));

            $tmpDir = rtrim((string) config('downloads.tmp_dir'), '/').'/transfer-'.$transfer->id;
            if (! $disk->exists($tmpDir)) {
                $disk->makeDirectory($tmpDir, 0755, true);
            }

            $absoluteTmpDir = $disk->path($tmpDir);
            $outputTemplate = $absoluteTmpDir.DIRECTORY_SEPARATOR.'download.%(ext)s';
            [$runtimeOptions, $runtimeCookieJarPath] = $this->buildRuntimeOptions($file, $absoluteTmpDir);

            $timeoutSeconds = (int) config('downloads.yt_dlp_timeout_seconds', 1800);

            $process = new Process($commandBuilder->build((string) $transfer->url, $outputTemplate, $runtimeOptions));
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
        } finally {
            if (is_string($runtimeCookieJarPath) && $runtimeCookieJarPath !== '' && is_file($runtimeCookieJarPath)) {
                @unlink($runtimeCookieJarPath);
            }

            if ($shouldClearAuthContext) {
                $this->clearFileAuthContext($file->id);
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

    /**
     * @return array{0: array{cookies_path?: string, user_agent?: string}, 1: string|null}
     */
    private function buildRuntimeOptions(File $file, string $absoluteTmpDir): array
    {
        $authContext = ExtensionAuthContext::sanitize(data_get($file->listing_metadata, 'auth_context'));
        if (! is_array($authContext)) {
            return [[], null];
        }

        $runtimeOptions = [];
        $cookieJarPath = null;

        $userAgent = trim((string) ($authContext['user_agent'] ?? ''));
        if ($userAgent !== '') {
            $runtimeOptions['user_agent'] = $userAgent;
        }

        $cookies = $authContext['cookies'] ?? null;
        if (is_array($cookies) && $cookies !== []) {
            $cookieJarPath = $this->writeCookieJar($absoluteTmpDir, $cookies);
            if ($cookieJarPath !== null) {
                $runtimeOptions['cookies_path'] = $cookieJarPath;
            }
        }

        return [$runtimeOptions, $cookieJarPath];
    }

    /**
     * @param  array<int, array{domain: string, path: string, name: string, value: string, secure: bool, host_only: bool, expires: int|null}>  $cookies
     */
    private function writeCookieJar(string $absoluteTmpDir, array $cookies): ?string
    {
        $lines = ['# Netscape HTTP Cookie File', '# Generated by Atlas for this transfer only.'];

        foreach ($cookies as $cookie) {
            $domain = trim((string) ($cookie['domain'] ?? ''));
            $name = trim((string) ($cookie['name'] ?? ''));
            if ($domain === '' || $name === '') {
                continue;
            }

            $hostOnly = (bool) ($cookie['host_only'] ?? false);
            if ($hostOnly && str_starts_with($domain, '.')) {
                $domain = ltrim($domain, '.');
            }

            $path = trim((string) ($cookie['path'] ?? '/'));
            if ($path === '') {
                $path = '/';
            }

            $value = (string) ($cookie['value'] ?? '');
            $value = str_replace(["\t", "\r", "\n"], '', $value);

            $includeSubdomains = $hostOnly ? 'FALSE' : 'TRUE';
            $isSecure = (bool) ($cookie['secure'] ?? false) ? 'TRUE' : 'FALSE';
            $expires = is_numeric($cookie['expires'] ?? null) && (int) $cookie['expires'] > 0
                ? (string) ((int) $cookie['expires'])
                : '0';

            $lines[] = implode("\t", [
                $domain,
                $includeSubdomains,
                $path,
                $isSecure,
                $expires,
                $name,
                $value,
            ]);
        }

        if (count($lines) <= 2) {
            return null;
        }

        $cookieJarPath = $absoluteTmpDir.DIRECTORY_SEPARATOR.'auth-cookies.txt';
        $written = @file_put_contents($cookieJarPath, implode(PHP_EOL, $lines).PHP_EOL);

        return $written === false ? null : $cookieJarPath;
    }

    private function clearFileAuthContext(int $fileId): void
    {
        $file = File::query()->find($fileId);
        if (! $file) {
            return;
        }

        $metadata = $file->listing_metadata;
        if (! is_array($metadata) || ! array_key_exists('auth_context', $metadata)) {
            return;
        }

        unset($metadata['auth_context']);
        $metadata = array_filter($metadata, static fn ($value) => $value !== null && $value !== '');

        $file->forceFill([
            'listing_metadata' => $metadata !== [] ? $metadata : null,
        ])->save();
    }
}
