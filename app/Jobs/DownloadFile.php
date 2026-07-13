<?php

namespace App\Jobs;

use App\Enums\DownloadTransferStatus;
use App\Events\DownloadTransferCreated;
use App\Events\DownloadTransferProgressUpdated;
use App\Jobs\Downloads\PumpDomainDownloads;
use App\Models\DownloadChunk;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Services\Downloads\DownloadTransferPayload;
use App\Services\Downloads\DownloadTransferRuntimeStore;
use App\Services\Downloads\DownloadTransferTempDirectory;
use App\Services\Downloads\DownloadUrlResolver;
use App\Services\Downloads\ResolvedDownloadUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class DownloadFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array{
     *     cookies?: list<array{
     *         name: string,
     *         value: string,
     *         domain: string,
     *         path: string,
     *         secure: bool,
     *         http_only: bool,
     *         host_only: bool,
     *         expires_at: int|null
     *     }>,
     *     user_id?: int,
     *     provider_url_expires_at?: int,
     *     provider_url_refresh_attempted?: bool,
     *     user_agent?: string
     * }  $runtimeContext
     */
    public function __construct(public int $fileId, public bool $forceDownload = false, public array $runtimeContext = [])
    {
        $this->onQueue('downloads');
    }

    public function handle(?DownloadTransferRuntimeStore $runtimeStore = null, ?DownloadUrlResolver $downloadUrlResolver = null): void
    {
        $runtimeStore ??= app(DownloadTransferRuntimeStore::class);
        $downloadUrlResolver ??= app(DownloadUrlResolver::class);

        $file = File::find($this->fileId);

        if (! $file || ! $file->url) {
            return;
        }

        if ($file->downloaded && ! empty($file->path)) {
            if ($this->isInvalidDownloadedFile($file)) {
                $this->repairDownloadedFile($file);
            } elseif (! $this->forceDownload) {
                return;
            }
        }

        $activeStatuses = [
            DownloadTransferStatus::PENDING,
            DownloadTransferStatus::QUEUED,
            DownloadTransferStatus::PREPARING,
            DownloadTransferStatus::DOWNLOADING,
            DownloadTransferStatus::ASSEMBLING,
            DownloadTransferStatus::PAUSED,
        ];

        $existing = DownloadTransfer::query()
            ->where('file_id', $file->id)
            ->whereIn('status', $activeStatuses)
            ->latest('id')
            ->first();

        if ($existing) {
            $this->maybeRefreshRuntimeContext($runtimeStore, $existing);
            PumpDomainDownloads::dispatch((string) $existing->domain);
        } else {
            $resolvedDownload = $downloadUrlResolver->resolve($file, $this->runtimeContext);
            $this->rememberResolvedDownloadUrl($resolvedDownload);
            $downloadUrl = $resolvedDownload->url;
            $domain = $this->extractDomain($downloadUrl) ?? 'unknown';
            $failedTransfer = $this->matchingFailedTransfer($file, $downloadUrl);

            if ($failedTransfer) {
                $transfer = $this->resetFailedTransferForRetry(
                    $failedTransfer,
                    $file,
                    $downloadUrl,
                    $domain,
                    $resolvedDownload->filesize,
                );
                if (! $transfer) {
                    $current = DownloadTransfer::query()
                        ->where('file_id', $file->id)
                        ->whereIn('status', $activeStatuses)
                        ->latest('id')
                        ->first();
                    if ($current) {
                        $this->maybeRefreshRuntimeContext($runtimeStore, $current);
                        PumpDomainDownloads::dispatch((string) $current->domain);
                    }

                    return;
                }

                $this->maybeRefreshRuntimeContext($runtimeStore, $transfer);
                $this->broadcastTransferProgress($transfer);
                PumpDomainDownloads::dispatch($domain);

                return;
            }

            $transfer = DownloadTransfer::query()->create([
                'file_id' => $file->id,
                'url' => $downloadUrl,
                'domain' => $domain,
                'status' => DownloadTransferStatus::PENDING,
                'bytes_total' => $resolvedDownload->filesize !== null && $resolvedDownload->filesize > 0 ? $resolvedDownload->filesize : null,
                'bytes_downloaded' => 0,
                'last_broadcast_percent' => 0,
            ]);

            $transfer->setRelation('file', $file);
            try {
                event(new DownloadTransferCreated(DownloadTransferPayload::forQueued($transfer)));
            } catch (\Throwable) {
                // Broadcast errors shouldn't fail downloads.
            }

            $this->storeRuntimeContext($runtimeStore, $transfer->id);
            PumpDomainDownloads::dispatch($domain);
        }
    }

    private function matchingFailedTransfer(File $file, string $downloadUrl): ?DownloadTransfer
    {
        return DownloadTransfer::query()
            ->where('file_id', $file->id)
            ->where('status', DownloadTransferStatus::FAILED)
            ->where('url', $downloadUrl)
            ->latest('id')
            ->first();
    }

    private function resetFailedTransferForRetry(
        DownloadTransfer $transfer,
        File $file,
        string $downloadUrl,
        string $domain,
        ?int $filesize,
    ): ?DownloadTransfer {
        $expectedAttempt = (int) ($transfer->attempt ?? 0);

        return DB::transaction(function () use ($transfer, $file, $downloadUrl, $domain, $filesize, $expectedAttempt): ?DownloadTransfer {
            $current = DownloadTransfer::query()->lockForUpdate()->find($transfer->id);
            if (! $current
                || $current->status !== DownloadTransferStatus::FAILED
                || (int) ($current->attempt ?? 0) !== $expectedAttempt
                || $current->file_id !== $file->id
                || $current->url !== $downloadUrl) {
                return null;
            }

            $currentFile = File::query()->lockForUpdate()->find($current->file_id);
            if (! $currentFile) {
                return null;
            }

            $current->setRelation('file', $currentFile);
            $this->cleanupTransferParts($current);
            $current->forceFill([
                'url' => $downloadUrl,
                'domain' => $domain,
                'status' => DownloadTransferStatus::PENDING,
                'bytes_total' => $filesize !== null && $filesize > 0 ? $filesize : null,
                'bytes_downloaded' => 0,
                'last_broadcast_percent' => 0,
                'batch_id' => null,
                'queued_at' => null,
                'started_at' => null,
                'finished_at' => null,
                'failed_at' => null,
                'error' => null,
                'attempt' => $expectedAttempt + 1,
            ])->save();
            $currentFile->forceFill(['download_progress' => 0])->save();

            return $current;
        });
    }

    private function cleanupTransferParts(DownloadTransfer $transfer): void
    {
        DownloadChunk::query()
            ->where('download_transfer_id', $transfer->id)
            ->delete();

        $disk = Storage::disk(config('downloads.disk'));
        $tmpDir = app(DownloadTransferTempDirectory::class)->forTransfer($transfer);

        if ($disk->exists($tmpDir)) {
            $disk->deleteDirectory($tmpDir);
        }
    }

    private function broadcastTransferProgress(DownloadTransfer $transfer): void
    {
        try {
            event(new DownloadTransferProgressUpdated(
                DownloadTransferPayload::forProgress($transfer, (int) ($transfer->last_broadcast_percent ?? 0))
            ));
        } catch (\Throwable) {
            // Broadcast errors shouldn't fail downloads.
        }
    }

    private function storeRuntimeContext(DownloadTransferRuntimeStore $runtimeStore, int $transferId): void
    {
        if ($this->runtimeContext === []) {
            return;
        }

        $runtimeStore->putForTransfer($transferId, $this->runtimeContext);
    }

    private function rememberResolvedDownloadUrl(ResolvedDownloadUrl $resolvedDownload): void
    {
        if (! $resolvedDownload->providerResolved) {
            return;
        }

        $this->runtimeContext['provider_url_refresh_attempted'] = false;
        if ($resolvedDownload->expiresAt !== null) {
            $this->runtimeContext['provider_url_expires_at'] = $resolvedDownload->expiresAt->timestamp;
        } else {
            unset($this->runtimeContext['provider_url_expires_at']);
        }
    }

    private function maybeRefreshRuntimeContext(DownloadTransferRuntimeStore $runtimeStore, DownloadTransfer $transfer): void
    {
        if ($this->runtimeContext === []) {
            return;
        }

        $storedContext = $runtimeStore->getForTransfer($transfer->id);
        if ($this->shouldReplaceRuntimeContext($transfer) || $storedContext === []) {
            $runtimeStore->putForTransfer($transfer->id, [...$storedContext, ...$this->runtimeContext]);
        }
    }

    private function shouldReplaceRuntimeContext(DownloadTransfer $transfer): bool
    {
        return in_array($transfer->status, [
            DownloadTransferStatus::PENDING,
            DownloadTransferStatus::QUEUED,
        ], true);
    }

    private function isInvalidDownloadedFile(File $file): bool
    {
        if (! $file->path) {
            return true;
        }

        $disk = Storage::disk(config('downloads.disk'));
        if (! $disk->exists($file->path)) {
            return true;
        }

        $tagName = data_get($file->listing_metadata, 'tag_name');
        if (! in_array($tagName, ['video', 'iframe'], true)) {
            return false;
        }

        $downloadVia = data_get($file->listing_metadata, 'download_via');
        $pageUrl = data_get($file->listing_metadata, 'page_url');
        $shouldExpectAudio = $downloadVia === 'yt-dlp'
            || $this->isYoutubeUrl((string) $file->url)
            || $this->isYoutubeUrl(is_string($pageUrl) ? $pageUrl : '');

        if ($shouldExpectAudio) {
            $hasAudio = $this->hasAudioStream($file);
            if ($hasAudio === false) {
                return true;
            }
        }

        $path = strtolower((string) $file->path);
        if (str_ends_with($path, '.html') || str_ends_with($path, '.htm')) {
            return true;
        }

        $mime = strtolower((string) ($file->mime_type ?? ''));
        if ($mime !== '' && str_starts_with($mime, 'text/html')) {
            return true;
        }

        return false;
    }

    private function isYoutubeUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        try {
            $host = parse_url($url, PHP_URL_HOST);
            if (! is_string($host) || $host === '') {
                return false;
            }

            $host = strtolower($host);

            return $host === 'youtu.be' || str_ends_with($host, '.youtube.com') || $host === 'youtube.com';
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasAudioStream(File $file): ?bool
    {
        if (! $file->path) {
            return null;
        }

        $disk = Storage::disk(config('downloads.disk'));
        if (! $disk->exists($file->path)) {
            return null;
        }

        $ffprobe = $this->resolveFfprobePath();
        if (! $ffprobe) {
            return null;
        }

        $fullPath = $disk->path($file->path);

        try {
            $process = new Process([
                $ffprobe,
                '-hide_banner',
                '-v',
                'error',
                '-select_streams',
                'a',
                '-show_entries',
                'stream=index',
                '-of',
                'csv=p=0',
                $fullPath,
            ]);
            $process->setTimeout(10);
            $process->run();

            if (! $process->isSuccessful()) {
                return null;
            }

            return trim($process->getOutput()) !== '';
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveFfprobePath(): ?string
    {
        $ffmpeg = (string) config('downloads.ffmpeg_path', 'ffmpeg');
        if ($ffmpeg === '') {
            return null;
        }

        // Windows: ffprobe sits next to ffmpeg in the same folder.
        if (str_ends_with(strtolower($ffmpeg), 'ffmpeg.exe')) {
            $candidate = substr($ffmpeg, 0, -strlen('ffmpeg.exe')).'ffprobe.exe';

            return is_file($candidate) ? $candidate : null;
        }

        // Linux/macOS: ffprobe is usually available alongside ffmpeg.
        if (str_ends_with(strtolower($ffmpeg), DIRECTORY_SEPARATOR.'ffmpeg')) {
            $candidate = substr($ffmpeg, 0, -strlen('ffmpeg')).'ffprobe';

            return is_file($candidate) ? $candidate : 'ffprobe';
        }

        return 'ffprobe';
    }

    private function repairDownloadedFile(File $file): void
    {
        $disk = Storage::disk(config('downloads.disk'));

        $path = $file->path;
        if (is_string($path) && $path !== '' && $disk->exists($path)) {
            try {
                $disk->delete($path);
            } catch (\Throwable) {
                // If cleanup fails we can still retry the download.
            }
        }

        $previewPath = $file->preview_path;
        if (is_string($previewPath) && $previewPath !== '' && $disk->exists($previewPath)) {
            try {
                $disk->delete($previewPath);
            } catch (\Throwable) {
                // Ignore cleanup failures.
            }
        }

        $posterPath = $file->poster_path;
        if (is_string($posterPath) && $posterPath !== '' && $disk->exists($posterPath)) {
            try {
                $disk->delete($posterPath);
            } catch (\Throwable) {
                // Ignore cleanup failures.
            }
        }

        $pageUrl = data_get($file->listing_metadata, 'page_url');
        if (! is_string($pageUrl) || $pageUrl === '') {
            $pageUrl = null;
        }

        $file->forceFill([
            'downloaded' => false,
            'downloaded_at' => null,
            'download_progress' => 0,
            'path' => null,
            'preview_path' => null,
            'poster_path' => null,
            'size' => null,
            'mime_type' => null,
            'ext' => null,
            // Prefer the actual page URL for video/iframe downloads (yt-dlp fallback uses it).
            'url' => $pageUrl ?? $file->url,
            'referrer_url' => $pageUrl ?? $file->referrer_url,
        ])->save();
    }

    private function extractDomain(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return $host ? strtolower($host) : null;
    }
}
