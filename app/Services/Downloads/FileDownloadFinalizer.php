<?php

namespace App\Services\Downloads;

use App\Models\File;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\MimeTypeDetection\FinfoMimeTypeDetector;

class FileDownloadFinalizer
{
    public function finalize(File $file, string $downloadedPath, ?string $contentTypeHeader = null): void
    {
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

        $updates = [
            'path' => $finalPath,
            'downloaded' => true,
            'downloaded_at' => now(),
        ];

        $mimeType = $this->getMimeTypeFromFile($absolutePath, $contentTypeHeader);
        if ($this->isImageMimeType($mimeType)) {
            $thumbnailPath = $this->generateThumbnailFromFile($disk, $absolutePath, $storedFilename, $hashForSegmentation);
            if ($thumbnailPath) {
                $updates['thumbnail_path'] = $thumbnailPath;
            }
        }

        if ($file->blacklisted_at !== null) {
            $updates['blacklisted_at'] = null;
            $updates['blacklist_reason'] = null;
        }

        $file->update($updates);
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
