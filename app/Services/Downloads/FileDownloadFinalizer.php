<?php

namespace App\Services\Downloads;

use App\Models\File;
use App\Services\FilePreviewService;
use App\Services\Library\LibraryIndexSyncDispatcher;
use App\Services\MetricsService;
use App\Support\AtlasStorage;
use App\Support\FileMimeType;
use Illuminate\Support\Facades\Storage;
use League\MimeTypeDetection\FinfoMimeTypeDetector;

class FileDownloadFinalizer
{
    private const MIME_SNIFF_BYTES = 262144;

    public function __construct(
        private readonly FileDownloadPreviewAssetGenerator $previewAssetGenerator,
        private readonly AtlasStorage $appStorage,
    ) {}

    /**
     * @return array{preview_path?: string, poster_path?: string}
     */
    public function generatePreviewAssets(File $file, bool $force = false): array
    {
        if ($force) {
            return $this->previewAssetGenerator->regeneratePreviewAssets($file);
        }

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
        $hadPath = is_string($file->path) && $file->path !== '';
        $wasBlacklisted = $file->blacklisted_at !== null;
        $wasAutoBlacklisted = (bool) $file->auto_blacklisted;
        $hasTerminalPreviewCount = (int) $file->previewed_count >= FilePreviewService::FEED_REMOVED_PREVIEW_COUNT;

        $disk = Storage::disk(config('downloads.disk'));

        $absolutePath = $disk->path($downloadedPath);
        $detectedMimeType = $this->detectMimeTypeFromDownload($absolutePath, $contentTypeHeader);

        $extension = $this->resolveFinalExtension(
            $file,
            $detectedMimeType ? $this->mimeTypeToExtension($detectedMimeType) : null,
        );

        $storedFilename = $this->resolveStoredFilename($file, $extension);
        $hashForSegmentation = $this->appStorage->normalizeHash($file->hash) ?? hash('sha256', $storedFilename);

        $finalPath = $this->appStorage->uniqueSegmentedPath($disk, AtlasStorage::DOWNLOADS, $storedFilename, $hashForSegmentation);

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
        $mimeType = FileMimeType::canonicalize($detectedMimeType);
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
        if (! $file->ext || $file->ext === 'bin' || strtolower((string) $file->ext) !== strtolower($extension)) {
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
                    $resolvedMimeType,
                ),
            ];
        }

        if ($file->blacklisted_at !== null) {
            $updates['blacklisted_at'] = null;
        }

        if ($wasAutoBlacklisted) {
            $updates['auto_blacklisted'] = false;
        }

        if ($wasBlacklisted || $hasTerminalPreviewCount) {
            $updates['previewed_count'] = FilePreviewService::RECOVERED_PREVIEW_COUNT;
        }

        $metrics = app(MetricsService::class);
        if ($wasAutoBlacklisted) {
            $metrics->applyAutoBlacklistClear($file, countAsManualBlacklisted: ! $wasBlacklisted);
        }

        $file->update($updates);
        app(LibraryIndexSyncDispatcher::class)->files([$file->id]);

        $metrics->applyDownload($file, $wasDownloaded, $hadPath, $wasBlacklisted);
        if ($wasBlacklisted) {
            $metrics->applyBlacklistClear(
                $file,
                wasAutoBlacklisted: $wasAutoBlacklisted,
                hadTerminalPreviewCount: $hasTerminalPreviewCount,
            );
        }
    }

    private function resolveStoredFilename(File $file, string $extension): string
    {
        $baseFilename = $file->filename;
        if (! $baseFilename) {
            return $this->appStorage->randomStoredFilename($extension);
        }

        return $this->appStorage->storedFilename($baseFilename, $extension);
    }

    private function resolveFinalExtension(File $file, ?string $detectedExtension): string
    {
        $detectedExtension = $this->normalizeExtension($detectedExtension);
        $filenameExtension = $this->normalizeExtension(pathinfo((string) $file->filename, PATHINFO_EXTENSION) ?: null);
        $fileExtension = $this->normalizeExtension($file->ext);
        $urlExtension = $this->normalizeExtension($this->getExtensionFromUrl((string) $file->url));

        if ($detectedExtension) {
            return $this->equivalentPreferredExtension($detectedExtension, $filenameExtension)
                ?? $this->equivalentPreferredExtension($detectedExtension, $fileExtension)
                ?? $this->equivalentPreferredExtension($detectedExtension, $urlExtension)
                ?? $detectedExtension;
        }

        return $fileExtension ?? $urlExtension ?? 'bin';
    }

    private function equivalentPreferredExtension(string $detectedExtension, ?string $candidateExtension): ?string
    {
        if (! $candidateExtension || $candidateExtension === 'bin') {
            return null;
        }

        return $this->extensionsAreEquivalent($detectedExtension, $candidateExtension)
            ? $candidateExtension
            : null;
    }

    private function extensionsAreEquivalent(string $firstExtension, string $secondExtension): bool
    {
        if ($firstExtension === $secondExtension) {
            return true;
        }

        return in_array($firstExtension, ['jpg', 'jpeg'], true)
            && in_array($secondExtension, ['jpg', 'jpeg'], true);
    }

    private function normalizeExtension(?string $extension): ?string
    {
        if (! is_string($extension)) {
            return null;
        }

        $extension = strtolower(trim($extension, ". \t\n\r\0\x0B"));
        $extension = preg_replace('/[^a-z0-9]+/', '', $extension) ?? '';

        return $extension !== '' ? $extension : null;
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

    private function detectMimeTypeFromDownload(string $absolutePath, ?string $contentTypeHeader = null): ?string
    {
        if ($contentTypeHeader) {
            $mimeType = $this->extractMimeTypeFromHeader($contentTypeHeader);
            if ($mimeType && $mimeType !== 'application/octet-stream') {
                return $mimeType;
            }
        }

        return $this->sniffMimeTypeFromFile($absolutePath);
    }

    private function sniffMimeTypeFromFile(string $absolutePath): ?string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if (! $finfo) {
            return null;
        }

        try {
            $handle = @fopen($absolutePath, 'rb');
            if (! is_resource($handle)) {
                return null;
            }

            try {
                $buffer = fread($handle, self::MIME_SNIFF_BYTES);
            } finally {
                fclose($handle);
            }

            $mimeType = is_string($buffer) && $buffer !== ''
                ? finfo_buffer($finfo, $buffer) ?: null
                : null;
        } finally {
            finfo_close($finfo);
        }

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
}
