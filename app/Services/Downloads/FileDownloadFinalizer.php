<?php

namespace App\Services\Downloads;

use App\Models\File;
use App\Services\MetricsService;
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
        $mimeType = $file->mime_type ?? $this->getMimeTypeFromFile($absolutePath);

        $updates = [];

        if ($this->isImageMimeType($mimeType) && ! $file->preview_path) {
            $storedFilename = basename($file->path);
            $hashForSegmentation = $this->normalizeHash($file->hash) ?? hash('sha256', $storedFilename);

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

    public function finalize(File $file, string $downloadedPath, ?string $contentTypeHeader = null): void
    {
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
        if (is_int($size) && $size > 0 && (! $file->size || $file->size <= 0)) {
            $updates['size'] = $size;
        }

        $mimeType = $this->getMimeTypeFromFile($absolutePath, $contentTypeHeader);
        if ($this->isImageMimeType($mimeType)) {
            $previewPath = $this->generateThumbnailFromFile($disk, $absolutePath, $storedFilename, $hashForSegmentation);
            if ($previewPath) {
                $updates['preview_path'] = $previewPath;
            }
        }
        if ($this->isVideoMimeType($mimeType)) {
            [$previewPath, $posterPath] = $this->generateVideoPreview($disk, $absolutePath, $finalPath);

            if ($previewPath) {
                $updates['preview_path'] = $previewPath;
            }
            if ($posterPath) {
                $updates['poster_path'] = $posterPath;
            }
        }

        if ($file->blacklisted_at !== null) {
            $updates['blacklisted_at'] = null;
            $updates['blacklist_reason'] = null;
        }

        $file->update($updates);

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
        if (! $mimeType) {
            return false;
        }

        return str_starts_with(strtolower($mimeType), 'video/');
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
        $posterSecond = (float) config('downloads.video_poster_second', 1);
        $timeout = (int) config('downloads.ffmpeg_timeout_seconds', 120);

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
            "scale={$previewWidth}:-2",
            '-c:v',
            'libx264',
            '-preset',
            'veryfast',
            '-crf',
            '28',
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
        $fileContents = @file_get_contents($absolutePath);
        if ($fileContents === false) {
            return null;
        }

        $image = @imagecreatefromstring($fileContents);
        if (! $image) {
            return null;
        }

        $originalWidth = imagesx($image);
        $originalHeight = imagesy($image);

        if ($originalWidth <= 0 || $originalHeight <= 0) {
            imagedestroy($image);

            return null;
        }

        $targetWidth = 450;
        $aspectRatio = $originalWidth / $originalHeight;

        if ($originalWidth <= $targetWidth) {
            $thumbnailWidth = $originalWidth;
            $thumbnailHeight = $originalHeight;
        } else {
            $thumbnailWidth = $targetWidth;
            $thumbnailHeight = (int) round($targetWidth / $aspectRatio);
        }

        $thumbnail = imagecreatetruecolor($thumbnailWidth, $thumbnailHeight);

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($extension, ['png', 'gif', 'webp'], true)) {
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

        $thumbnailFilename = pathinfo($filename, PATHINFO_FILENAME).'_thumb.'.pathinfo($filename, PATHINFO_EXTENSION);
        $thumbnailPath = $this->generateSegmentedPath('thumbnails', $thumbnailFilename, $hash);

        $thumbnailDirectory = dirname($thumbnailPath);
        if (! $disk->exists($thumbnailDirectory)) {
            $disk->makeDirectory($thumbnailDirectory, 0755, true);
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'thumb_');
        if (! $tempFile) {
            imagedestroy($thumbnail);

            return null;
        }

        $saved = false;

        switch ($extension) {
            case 'jpg':
            case 'jpeg':
                $saved = imagejpeg($thumbnail, $tempFile, 85);
                break;
            case 'png':
                $saved = imagepng($thumbnail, $tempFile, 6);
                break;
            case 'gif':
                $saved = imagegif($thumbnail, $tempFile);
                break;
            case 'webp':
                if (function_exists('imagewebp')) {
                    $saved = imagewebp($thumbnail, $tempFile, 85);
                }
                break;
        }

        imagedestroy($thumbnail);

        if (! $saved || ! file_exists($tempFile)) {
            @unlink($tempFile);

            return null;
        }

        $thumbnailContents = file_get_contents($tempFile);
        @unlink($tempFile);

        if ($thumbnailContents === false) {
            return null;
        }

        $disk->put($thumbnailPath, $thumbnailContents);

        return $thumbnailPath;
    }
}
