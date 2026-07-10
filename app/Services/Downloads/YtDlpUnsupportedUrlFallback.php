<?php

namespace App\Services\Downloads;

use App\Enums\DownloadTransferStatus;
use App\Models\DownloadTransfer;
use App\Models\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class YtDlpUnsupportedUrlFallback
{
    public const REASON = 'yt-dlp-unsupported-native-fallback';

    public function __construct(
        private readonly DownloadTransferTempDirectory $tempDirectory,
    ) {}

    public function recover(DownloadTransfer $transfer, string $failureMessage, int $attempt): YtDlpUnsupportedUrlFallbackResult|false
    {
        if (! YtDlpFailureMessage::isUnsupportedUrl($failureMessage)) {
            return false;
        }

        return DB::transaction(function () use ($transfer, $attempt): YtDlpUnsupportedUrlFallbackResult|false {
            $lockedTransfer = DownloadTransfer::query()->lockForUpdate()->find($transfer->id);
            if (! $lockedTransfer
                || (int) ($lockedTransfer->attempt ?? 0) !== $attempt
                || ! in_array($lockedTransfer->status, [
                    DownloadTransferStatus::DOWNLOADING,
                    DownloadTransferStatus::ASSEMBLING,
                ], true)) {
                $this->cleanupAttempt($transfer->id, $attempt);

                return new YtDlpUnsupportedUrlFallbackResult($this->domains(
                    (string) $transfer->domain,
                    (string) ($lockedTransfer?->domain ?? ''),
                ));
            }

            $file = File::query()->lockForUpdate()->find($lockedTransfer->file_id);
            $nativeUrl = self::nativeUrl($file);
            if (! $file || $nativeUrl === null || $nativeUrl === $lockedTransfer->url) {
                return false;
            }

            $oldDomain = (string) $lockedTransfer->domain;
            $newDomain = self::domain($nativeUrl) ?? 'unknown';
            $metadata = is_array($file->listing_metadata) ? $file->listing_metadata : [];
            unset($metadata['download_via']);
            $metadata['download_via_reason'] = self::REASON;

            if (! $this->cleanupAttempt($transfer->id, $attempt)) {
                return false;
            }

            $file->forceFill([
                'download_progress' => 0,
                'listing_metadata' => $metadata,
            ])->save();

            $lockedTransfer->forceFill([
                'attempt' => $attempt + 1,
                'batch_id' => null,
                'bytes_downloaded' => 0,
                'bytes_total' => null,
                'domain' => $newDomain,
                'error' => null,
                'failed_at' => null,
                'finished_at' => null,
                'last_broadcast_percent' => 0,
                'queued_at' => null,
                'started_at' => null,
                'status' => DownloadTransferStatus::PENDING,
                'url' => $nativeUrl,
            ])->save();

            return new YtDlpUnsupportedUrlFallbackResult($this->domains($oldDomain, $newDomain));
        });
    }

    public static function isEstablishedForFile(?File $file): bool
    {
        return $file
            && data_get($file->listing_metadata, 'download_via_reason') === self::REASON
            && self::nativeUrl($file) !== null;
    }

    public static function isNativeTransfer(DownloadTransfer $transfer): bool
    {
        if (! self::isEstablishedForFile($transfer->file)) {
            return false;
        }

        return self::nativeUrl($transfer->file) === $transfer->url;
    }

    public static function usesYtDlp(DownloadTransfer $transfer): bool
    {
        return data_get($transfer->file?->listing_metadata, 'download_via') === 'yt-dlp'
            && ! self::isNativeTransfer($transfer);
    }

    public static function nativeUrl(?File $file): ?string
    {
        if (! $file) {
            return null;
        }

        $metadata = is_array($file->listing_metadata) ? $file->listing_metadata : [];
        $extensionChannel = $metadata['extension_channel'] ?? null;
        $tagName = $metadata['tag_name'] ?? null;
        if (! is_string($extensionChannel)
            || trim($extensionChannel) === ''
            || ! in_array($tagName, ['video', 'iframe'], true)) {
            return null;
        }

        $candidate = $file->preview_url;
        if (! is_string($candidate) || trim($candidate) === '') {
            return null;
        }

        $candidate = trim($candidate);
        $scheme = parse_url($candidate, PHP_URL_SCHEME);
        $host = parse_url($candidate, PHP_URL_HOST);
        if (! is_string($scheme)
            || ! in_array(strtolower($scheme), ['http', 'https'], true)
            || ! is_string($host)
            || $host === '') {
            return null;
        }

        return $candidate;
    }

    private static function domain(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    private function cleanupAttempt(int $transferId, int $attempt): bool
    {
        $disk = Storage::disk(config('downloads.disk'));
        $directory = $this->tempDirectory->attempt($transferId, $attempt);
        if ($disk->exists($directory)) {
            $disk->deleteDirectory($directory);
        }

        return ! $disk->exists($directory);
    }

    /**
     * @return list<string>
     */
    private function domains(string ...$domains): array
    {
        return array_values(array_unique(array_filter($domains, fn (string $domain): bool => $domain !== '')));
    }
}
