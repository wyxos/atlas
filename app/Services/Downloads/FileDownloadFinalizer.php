<?php

namespace App\Services\Downloads;

use App\Models\File;
use App\Services\Local\LocalBrowseIndexSyncService;
use App\Services\MetricsService;
use App\Support\FileMimeType;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\MimeTypeDetection\FinfoMimeTypeDetector;

class FileDownloadFinalizer
{
    public function __construct(private readonly FileDownloadPreviewAssetGenerator $previewAssetGenerator) {}

    /**
     * @return array{preview_path?: string, poster_path?: string}
     */
    public function generatePreviewAssets(File $file): array
    {
        return $this->previewAssetGenerator->generatePreviewAssets($file);
    }

    /**
     * @return array{preview_path?: string, poster_path?: string}
     */
    public function regenerateVideoPreviewAssets(File $file): array
    {
        return $this->previewAssetGenerator->regenerateVideoPreviewAssets($file);
    }

    public function finalize(
        File $file,
        string $downloadedPath,
        ?string $contentTypeHeader = null,
        bool $generatePreviews = true
    ): void {
        $wasDownloaded = (bool) $file->downloaded;
        $wasBlacklisted = $file->blacklisted_at !== null;

        $disk = Storage::disk(config('downloads.disk'));

        $absolutePath = $disk->path($downloadedPath);

        $extension = $file->ext
            ?? $this->getExtensionFromFile($absolutePath, $contentTypeHeader)
            ?? $this->getExtensionFromUrl((string) $file->url)
            ?? 'bin';

        $storedFilename = $this->resolveStoredFilename($file, $extension);
        $hashForSegmentation = $this->normalizeHash($file->hash) ?? hash('sha256', $storedFilename);

        $finalPath = $this->generateSegmentedPath('downloads', $storedFilename, $hashForSegmentation);

        if ($downloadedPath !== $finalPath) {
            $directory = dirname($finalPath);
            if (! $disk->exists($directory)) {
                $disk->makeDirectory($directory, 0755, true);
            }

            $disk->move($downloadedPath, $finalPath);
            $absolutePath = $disk->path($finalPath);
        }

        $size = $disk->exists($finalPath) ? $disk->size($finalPath) : null;
        $updates = [
            'path' => $finalPath,
            'downloaded' => true,
            'downloaded_at' => now(),
        ];

        // Ensure mime_type/ext reflect the actual downloaded file. This matters for yt-dlp downloads
        // where the source URL can be a page URL (text/html), not a direct media URL.
        $mimeType = FileMimeType::canonicalize($this->getMimeTypeFromFile($absolutePath, $contentTypeHeader));
        $currentMimeType = FileMimeType::canonicalize($file->mime_type);
        $resolvedMimeType = $mimeType ?? $currentMimeType;
        if (
            ! $file->mime_type
            || $file->mime_type === 'application/octet-stream'
            || str_starts_with((string) $file->mime_type, 'text/')
            || $resolvedMimeType !== $currentMimeType
        ) {
            $updates['mime_type'] = $resolvedMimeType;
        }
        if (! $file->ext || $file->ext === 'bin') {
            $updates['ext'] = $extension;
        }

        if (is_int($size) && $size > 0 && (! $file->size || $file->size <= 0)) {
            $updates['size'] = $size;
        }

        if ($generatePreviews) {
            $updates = [
                ...$updates,
                ...$this->previewAssetGenerator->generateFinalizedPreviewAssets(
                    $file,
                    $disk,
                    $absolutePath,
                    $finalPath,
                    $storedFilename,
                    $hashForSegmentation,
                    $resolvedMimeType,
                ),
            ];
        }

        if ($file->blacklisted_at !== null) {
            $updates['blacklisted_at'] = null;
        }

        $file->update($updates);
        app(LocalBrowseIndexSyncService::class)->syncFilesByIds([$file->id]);

        $metrics = app(MetricsService::class);
        $metrics->applyDownload($file, $wasDownloaded);
        if ($wasBlacklisted) {
            $metrics->applyBlacklistClear($file);
        }
    }

    private function generateStoredFilename(string $extension): string
    {
        return Str::random(40).'.'.$extension;
    }

    private function resolveStoredFilename(File $file, string $extension): string
    {
        $baseFilename = $file->filename;
        if (! $baseFilename) {
            return $this->generateStoredFilename($extension);
        }

        $suffix = '.'.strtolower($extension);
        if ($suffix !== '.' && str_ends_with(strtolower($baseFilename), $suffix)) {
            return $baseFilename;
        }

        return $baseFilename.$suffix;
    }

    private function normalizeHash(?string $hash): ?string
    {
        if (! $hash) {
            return null;
        }

        $hash = strtolower(trim($hash));
        if ($hash === '') {
            return null;
        }

        return preg_match('/^[a-f0-9]{4,}$/', $hash) === 1 ? $hash : null;
    }

    private function getExtensionFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! $path) {
            return null;
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return $extension ? strtolower($extension) : null;
    }

    private function getExtensionFromFile(string $absolutePath, ?string $contentTypeHeader = null): ?string
    {
        if ($contentTypeHeader) {
            $mimeType = $this->extractMimeTypeFromHeader($contentTypeHeader);
            if ($mimeType && $mimeType !== 'application/octet-stream') {
                $extension = $this->mimeTypeToExtension($mimeType);
                if ($extension) {
                    return $extension;
                }
            }
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if (! $finfo) {
            return null;
        }

        $mimeType = finfo_file($finfo, $absolutePath) ?: null;
        finfo_close($finfo);

        if (! $mimeType || $mimeType === 'application/octet-stream') {
            return null;
        }

        return $this->mimeTypeToExtension($mimeType);
    }

    private function getMimeTypeFromFile(string $absolutePath, ?string $contentTypeHeader = null): ?string
    {
        if ($contentTypeHeader) {
            $mimeType = $this->extractMimeTypeFromHeader($contentTypeHeader);
            if ($mimeType && $mimeType !== 'application/octet-stream') {
                return $mimeType;
            }
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if (! $finfo) {
            return null;
        }

        $mimeType = finfo_file($finfo, $absolutePath) ?: null;
        finfo_close($finfo);

        return $mimeType && $mimeType !== 'application/octet-stream' ? $mimeType : null;
    }

    private function extractMimeTypeFromHeader(string $contentType): ?string
    {
        $parts = explode(';', $contentType);
        $mimeType = trim($parts[0]);

        return $mimeType !== '' ? $mimeType : null;
    }

    private function mimeTypeToExtension(string $mimeType): ?string
    {
        static $detector = null;

        if ($detector === null) {
            $detector = new FinfoMimeTypeDetector;
        }

        return $detector->lookupExtension($mimeType);
    }

    private function generateSegmentedPath(string $base, string $filename, string $hash): string
    {
        $subfolder1 = substr($hash, 0, 2);
        $subfolder2 = substr($hash, 2, 2);

        return "{$base}/{$subfolder1}/{$subfolder2}/{$filename}";
    }
}
