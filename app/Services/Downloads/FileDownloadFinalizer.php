<?php

namespace App\Services\Downloads;

use App\Models\File;
use App\Models\FileMetadata;
use App\Services\Local\LocalBrowseIndexSyncService;
use App\Services\MetricsService;
use App\Support\FileMimeType;
use App\Support\VideoPreviewSamplingPlan;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use Symfony\Component\Process\Process;

class FileDownloadFinalizer
{
    /**
     * @return array{preview_path?: string, poster_path?: string}
     */
    public function generatePreviewAssets(File $file): array
    {
        if (! $file->path) {
            return [];
        }

        $disk = Storage::disk(config('downloads.disk'));

        if (! $disk->exists($file->path)) {
            return [];
        }

        $absolutePath = $disk->path($file->path);
        $mimeType = FileMimeType::canonicalize($file->mime_type ?? $this->getMimeTypeFromFile($absolutePath));

        $updates = [];

        if ($this->isImageMimeType($mimeType) && ! $file->preview_path) {
            $storedFilename = basename($file->path);
            $hashForSegmentation = $this->normalizeHash($file->hash) ?? hash('sha256', $storedFilename);

            $this->persistImageDimensions($file, $absolutePath);
            $previewPath = $this->generateThumbnailFromFile($disk, $absolutePath, $storedFilename, $hashForSegmentation);
            if ($previewPath) {
                $updates['preview_path'] = $previewPath;
            }
        }

        if ($this->isVideoMimeType($mimeType) && (! $file->preview_path || ! $file->poster_path)) {
            [$previewPath, $posterPath] = $this->generateVideoPreview($disk, $absolutePath, $file->path);

            if ($previewPath && ! $file->preview_path) {
                $updates['preview_path'] = $previewPath;
            }
            if ($posterPath && ! $file->poster_path) {
                $updates['poster_path'] = $posterPath;
            }
        }

        return $updates;
    }

    /**
     * @return array{preview_path?: string, poster_path?: string}
     */
    public function regenerateVideoPreviewAssets(File $file): array
    {
        if (! $file->path) {
            return [];
        }

        $disk = Storage::disk(config('downloads.disk'));
        if (! $disk->exists($file->path)) {
            return [];
        }

        $absolutePath = $disk->path($file->path);
        $mimeType = FileMimeType::canonicalize($file->mime_type ?? $this->getMimeTypeFromFile($absolutePath));
        if (! $this->isVideoMimeType($mimeType)) {
            return [];
        }

        [$previewPath, $posterPath] = $this->generateVideoPreview($disk, $absolutePath, $file->path);

        $updates = [];
        if ($previewPath) {
            $updates['preview_path'] = $previewPath;
        }
        if ($posterPath) {
            $updates['poster_path'] = $posterPath;
        }

        return $updates;
    }

    public function finalize(
        File $file,
        string $downloadedPath,
        ?string $contentTypeHeader = null,
        bool $generatePreviews = true
    ): void {
        $wasDownloaded = (bool) $file->downloaded;
        $wasBlacklisted = $file->blacklisted_at !== null;
        $wasManual = is_string($file->blacklist_reason) && $file->blacklist_reason !== '';

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
            if ($this->isImageMimeType($resolvedMimeType)) {
                $this->persistImageDimensions($file, $absolutePath);
                $previewPath = $this->generateThumbnailFromFile($disk, $absolutePath, $storedFilename, $hashForSegmentation);
                if ($previewPath) {
                    $updates['preview_path'] = $previewPath;
                }
            }
            if ($this->isVideoMimeType($resolvedMimeType)) {
                [$previewPath, $posterPath] = $this->generateVideoPreview($disk, $absolutePath, $finalPath);

                if ($previewPath) {
                    $updates['preview_path'] = $previewPath;
                }
                if ($posterPath) {
                    $updates['poster_path'] = $posterPath;
                }
            }
        }

        if ($file->blacklisted_at !== null) {
            $updates['blacklisted_at'] = null;
            $updates['blacklist_reason'] = null;
        }

        $file->update($updates);
        app(LocalBrowseIndexSyncService::class)->syncFilesByIds([$file->id]);

        $metrics = app(MetricsService::class);
        $metrics->applyDownload($file, $wasDownloaded);
        if ($wasBlacklisted) {
            $metrics->applyBlacklistClear($file, $wasManual);
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

    private function isImageMimeType(?string $mimeType): bool
    {
        if (! $mimeType) {
            return false;
        }

        $mimeType = strtolower($mimeType);

        return str_starts_with($mimeType, 'image/')
            && ! in_array($mimeType, ['image/svg+xml', 'image/x-icon'], true);
    }

    private function isVideoMimeType(?string $mimeType): bool
    {
        return FileMimeType::isVideo($mimeType);
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function generateVideoPreview(Filesystem $disk, string $absolutePath, string $finalPath): array
    {
        $ffmpegPath = (string) config('downloads.ffmpeg_path');
        $ffmpegPath = $this->resolveFfmpegPath($ffmpegPath);
        if (! $ffmpegPath) {
            return [null, null];
        }

        $previewWidth = (int) config('downloads.video_preview_width', 450);
        $previewStartSecond = (float) config('downloads.video_preview_start_second', 1);
        $previewTakeSeconds = (float) config('downloads.video_preview_take_seconds', 5);
        $previewSkipSeconds = (float) config('downloads.video_preview_skip_seconds', 10);
        $previewTakeCount = (int) config('downloads.video_preview_take_count', 5);
        $posterSecond = (float) config('downloads.video_poster_second', $previewStartSecond);
        $timeout = (int) config('downloads.ffmpeg_timeout_seconds', 120);
        $previewFilter = VideoPreviewSamplingPlan::selectFilter(
            $previewStartSecond,
            $previewTakeSeconds,
            $previewSkipSeconds,
            $previewTakeCount,
        ).",scale={$previewWidth}:-2";

        $directory = pathinfo($finalPath, PATHINFO_DIRNAME);
        $filename = pathinfo($finalPath, PATHINFO_FILENAME);
        $previewPath = $directory.'/'.$filename.'.preview.mp4';
        $posterPath = $directory.'/'.$filename.'.poster.jpg';

        if (! $disk->exists($directory)) {
            $disk->makeDirectory($directory, 0755, true);
        }

        $previewAbsolutePath = $disk->path($previewPath);
        $posterAbsolutePath = $disk->path($posterPath);

        $previewProcess = new Process([
            $ffmpegPath,
            '-y',
            '-i',
            $absolutePath,
            '-vf',
            $previewFilter,
            '-c:v',
            'libx264',
            '-preset',
            'veryfast',
            '-crf',
            '28',
            '-pix_fmt',
            'yuv420p',
            '-movflags',
            '+faststart',
            '-an',
            $previewAbsolutePath,
        ]);
        $previewProcess->setTimeout($timeout);

        try {
            $previewProcess->run();
        } catch (\Throwable) {
            // Ignore preview generation failures.
        }

        if (! $previewProcess->isSuccessful() || ! $disk->exists($previewPath)) {
            $previewPath = null;
        }

        $posterProcess = new Process([
            $ffmpegPath,
            '-y',
            '-ss',
            (string) $posterSecond,
            '-i',
            $absolutePath,
            '-frames:v',
            '1',
            '-vf',
            "scale={$previewWidth}:-2",
            $posterAbsolutePath,
        ]);
        $posterProcess->setTimeout($timeout);

        try {
            $posterProcess->run();
        } catch (\Throwable) {
            // Ignore poster generation failures.
        }

        if (! $posterProcess->isSuccessful() || ! $disk->exists($posterPath)) {
            $posterPath = null;
        }

        return [$previewPath, $posterPath];
    }

    private function resolveFfmpegPath(string $ffmpegPath): ?string
    {
        if ($ffmpegPath === '') {
            return null;
        }

        if (is_dir($ffmpegPath)) {
            $binary = DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'ffmpeg.exe' : 'ffmpeg');
            $ffmpegPath = rtrim($ffmpegPath, '\\/').$binary;
        }

        if (is_file($ffmpegPath)) {
            return $ffmpegPath;
        }

        return $ffmpegPath !== '' ? $ffmpegPath : null;
    }

    private function generateThumbnailFromFile(Filesystem $disk, string $absolutePath, string $filename, string $hash): ?string
    {
        $imageSize = @getimagesize($absolutePath);
        if (! is_array($imageSize)) {
            return null;
        }

        $originalWidth = isset($imageSize[0]) ? (int) $imageSize[0] : 0;
        $originalHeight = isset($imageSize[1]) ? (int) $imageSize[1] : 0;

        if ($originalWidth <= 0 || $originalHeight <= 0) {
            return null;
        }

        [$thumbnailWidth, $thumbnailHeight] = $this->resolveThumbnailDimensions($originalWidth, $originalHeight);

        $imageType = $this->detectImageType($absolutePath);
        $thumbnailExtension = $this->resolveThumbnailOutputExtension($imageType);
        $thumbnailFilename = pathinfo($filename, PATHINFO_FILENAME).'_thumb.'.$thumbnailExtension;
        $thumbnailPath = $this->generateSegmentedPath('thumbnails', $thumbnailFilename, $hash);

        $thumbnailDirectory = dirname($thumbnailPath);
        if (! $disk->exists($thumbnailDirectory)) {
            $disk->makeDirectory($thumbnailDirectory, 0755, true);
        }

        $thumbnailPathFromProcess = $this->generateThumbnailWithFfmpeg(
            $disk,
            $absolutePath,
            $thumbnailPath,
            $thumbnailWidth,
            $thumbnailHeight,
            $thumbnailExtension,
        );
        if ($thumbnailPathFromProcess) {
            return $thumbnailPathFromProcess;
        }

        if (! $this->canGenerateThumbnail($originalWidth, $originalHeight, $thumbnailWidth, $thumbnailHeight)) {
            return null;
        }

        $image = $this->createImageResourceFromFile($absolutePath, $imageType);
        if (! $image) {
            return null;
        }

        $thumbnail = imagecreatetruecolor($thumbnailWidth, $thumbnailHeight);

        if ($thumbnailExtension === 'png') {
            imagealphablending($thumbnail, false);
            imagesavealpha($thumbnail, true);
            $transparent = imagecolorallocatealpha($thumbnail, 0, 0, 0, 127);
            imagefill($thumbnail, 0, 0, $transparent);
        }

        imagecopyresampled(
            $thumbnail,
            $image,
            0,
            0,
            0,
            0,
            $thumbnailWidth,
            $thumbnailHeight,
            $originalWidth,
            $originalHeight
        );

        imagedestroy($image);

        $tempFile = tempnam(sys_get_temp_dir(), 'thumb_');
        if (! $tempFile) {
            imagedestroy($thumbnail);

            return null;
        }

        $saved = false;

        switch ($thumbnailExtension) {
            case 'jpg':
                $saved = imagejpeg($thumbnail, $tempFile, 85);
                break;
            case 'png':
                $saved = imagepng($thumbnail, $tempFile, 6);
                break;
        }

        imagedestroy($thumbnail);

        return $saved && file_exists($tempFile)
            ? $this->storeTempThumbnail($disk, $thumbnailPath, $tempFile)
            : null;
    }

    /**
     * @return array{0: int, 1: int}
     */
    protected function resolveThumbnailDimensions(int $originalWidth, int $originalHeight): array
    {
        $targetWidth = 450;

        if ($originalWidth <= $targetWidth) {
            return [$originalWidth, $originalHeight];
        }

        $aspectRatio = $originalWidth / $originalHeight;

        return [$targetWidth, (int) round($targetWidth / $aspectRatio)];
    }

    protected function canGenerateThumbnail(int $originalWidth, int $originalHeight, int $thumbnailWidth, int $thumbnailHeight): bool
    {
        $availableMemory = $this->availableMemoryForThumbnailGeneration();
        if ($availableMemory === null) {
            return true;
        }

        if ($availableMemory <= 0) {
            return false;
        }

        $estimatedUsage = $this->estimateThumbnailMemoryUsage($originalWidth, $originalHeight, $thumbnailWidth, $thumbnailHeight);

        return $estimatedUsage <= (int) floor($availableMemory * 0.5);
    }

    protected function availableMemoryForThumbnailGeneration(): ?int
    {
        $memoryLimit = $this->parseMemoryLimitToBytes(ini_get('memory_limit'));
        if ($memoryLimit === null) {
            return null;
        }

        return $memoryLimit - memory_get_usage(true);
    }

    protected function estimateThumbnailMemoryUsage(int $originalWidth, int $originalHeight, int $thumbnailWidth, int $thumbnailHeight): int
    {
        // GD keeps a decoded bitmap of the source image and the resized destination in memory.
        // Use a deliberately pessimistic estimate so large images are skipped instead of crashing a 128 MB worker.
        return (int) ceil(($originalWidth * $originalHeight * 8) + ($thumbnailWidth * $thumbnailHeight * 8) + (16 * 1024 * 1024));
    }

    protected function parseMemoryLimitToBytes(string|false $memoryLimit): ?int
    {
        if ($memoryLimit === false) {
            return null;
        }

        $memoryLimit = trim(strtolower($memoryLimit));
        if ($memoryLimit === '' || $memoryLimit === '-1') {
            return null;
        }

        if (! preg_match('/^(?<value>\d+)(?<unit>[kmg])?$/', $memoryLimit, $matches)) {
            return null;
        }

        $value = (int) $matches['value'];
        $unit = $matches['unit'] ?? '';

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    private function detectImageType(string $absolutePath): int|false
    {
        return function_exists('exif_imagetype') ? @exif_imagetype($absolutePath) : false;
    }

    private function resolveThumbnailOutputExtension(int|false $imageType): string
    {
        if (in_array($imageType, [IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
            return 'png';
        }

        if (defined('IMAGETYPE_AVIF') && $imageType === IMAGETYPE_AVIF) {
            return 'png';
        }

        return 'jpg';
    }

    private function generateThumbnailWithFfmpeg(
        Filesystem $disk,
        string $absolutePath,
        string $thumbnailPath,
        int $thumbnailWidth,
        int $thumbnailHeight,
        string $thumbnailExtension
    ): ?string {
        $ffmpegPath = $this->resolveFfmpegPath((string) config('downloads.ffmpeg_path'));
        if (! $ffmpegPath) {
            return null;
        }

        $baseTempFile = tempnam(sys_get_temp_dir(), 'thumb_');
        if (! $baseTempFile) {
            return null;
        }

        @unlink($baseTempFile);
        $tempFile = $baseTempFile.'.'.$thumbnailExtension;
        $timeout = (int) config('downloads.ffmpeg_timeout_seconds', 120);

        $process = new Process([
            $ffmpegPath,
            '-y',
            '-loglevel',
            'error',
            '-i',
            $absolutePath,
            '-vf',
            "scale={$thumbnailWidth}:{$thumbnailHeight}:force_original_aspect_ratio=decrease",
            '-frames:v',
            '1',
            '-update',
            '1',
            $tempFile,
        ]);
        $process->setTimeout($timeout);

        try {
            $process->run();
        } catch (\Throwable) {
            @unlink($tempFile);

            return null;
        }

        if (! $process->isSuccessful() || ! file_exists($tempFile)) {
            @unlink($tempFile);

            return null;
        }

        return $this->storeTempThumbnail($disk, $thumbnailPath, $tempFile);
    }

    private function storeTempThumbnail(Filesystem $disk, string $thumbnailPath, string $tempFile): ?string
    {
        $stream = fopen($tempFile, 'rb');
        if ($stream === false) {
            @unlink($tempFile);

            return null;
        }

        try {
            $stored = $disk->put($thumbnailPath, $stream);
        } finally {
            fclose($stream);
            @unlink($tempFile);
        }

        return $stored ? $thumbnailPath : null;
    }

    /**
     * @return \GdImage|false
     */
    private function createImageResourceFromFile(string $absolutePath, int|false $type)
    {
        if ($type === IMAGETYPE_JPEG) {
            return @imagecreatefromjpeg($absolutePath);
        }

        if ($type === IMAGETYPE_PNG) {
            return @imagecreatefrompng($absolutePath);
        }

        if ($type === IMAGETYPE_GIF) {
            return @imagecreatefromgif($absolutePath);
        }

        if ($type === IMAGETYPE_WEBP) {
            return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absolutePath) : false;
        }

        if (defined('IMAGETYPE_BMP') && $type === IMAGETYPE_BMP) {
            return function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($absolutePath) : false;
        }

        if (defined('IMAGETYPE_AVIF') && $type === IMAGETYPE_AVIF) {
            return function_exists('imagecreatefromavif') ? @imagecreatefromavif($absolutePath) : false;
        }

        return false;
    }

    /**
     * Ensure the file has real (downloaded) width/height stored for Masonry aspect ratio.
     * Extension ingests can provide rendered dimensions; we prefer the actual downloaded file.
     */
    private function persistImageDimensions(File $file, string $absolutePath): void
    {
        $size = @getimagesize($absolutePath);
        if (! is_array($size)) {
            return;
        }

        $width = isset($size[0]) ? (int) $size[0] : 0;
        $height = isset($size[1]) ? (int) $size[1] : 0;

        if ($width <= 0 || $height <= 0) {
            return;
        }

        // Keep listing_metadata width/height aligned so Masonry can render correct aspect ratio
        // even when FileMetadata isn't eager-loaded.
        $listing = is_array($file->listing_metadata) ? $file->listing_metadata : [];
        $listing['width'] = $width;
        $listing['height'] = $height;
        $file->listing_metadata = $listing;

        $meta = FileMetadata::query()->firstOrNew(['file_id' => $file->id]);
        $payload = is_array($meta->payload) ? $meta->payload : [];

        // Always prefer real downloaded dimensions.
        $payload['width'] = $width;
        $payload['height'] = $height;

        $meta->payload = $payload;
        $meta->save();
    }
}
