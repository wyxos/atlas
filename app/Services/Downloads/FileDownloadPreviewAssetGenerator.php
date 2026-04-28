<?php

namespace App\Services\Downloads;

use App\Models\File;
use App\Models\FileMetadata;
use App\Support\FileMimeType;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class FileDownloadPreviewAssetGenerator
{
    public function __construct(
        private readonly FileThumbnailMemoryGuard $memoryGuard,
        private readonly FileVideoPreviewGenerator $videoPreviewGenerator,
    ) {}

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
            [$previewPath, $posterPath] = $this->videoPreviewGenerator->generate($disk, $absolutePath, $file->path);

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

        [$previewPath, $posterPath] = $this->videoPreviewGenerator->generate($disk, $absolutePath, $file->path);

        $updates = [];
        if ($previewPath) {
            $updates['preview_path'] = $previewPath;
        }
        if ($posterPath) {
            $updates['poster_path'] = $posterPath;
        }

        return $updates;
    }

    /**
     * @return array{preview_path?: string, poster_path?: string}
     */
    public function generateFinalizedPreviewAssets(
        File $file,
        Filesystem $disk,
        string $absolutePath,
        string $finalPath,
        string $storedFilename,
        string $hashForSegmentation,
        ?string $mimeType,
    ): array {
        $updates = [];

        if ($this->isImageMimeType($mimeType)) {
            $this->persistImageDimensions($file, $absolutePath);
            $previewPath = $this->generateThumbnailFromFile($disk, $absolutePath, $storedFilename, $hashForSegmentation);
            if ($previewPath) {
                $updates['preview_path'] = $previewPath;
            }
        }

        if ($this->isVideoMimeType($mimeType)) {
            [$previewPath, $posterPath] = $this->videoPreviewGenerator->generate($disk, $absolutePath, $finalPath);

            if ($previewPath) {
                $updates['preview_path'] = $previewPath;
            }
            if ($posterPath) {
                $updates['poster_path'] = $posterPath;
            }
        }

        return $updates;
    }

    private function getMimeTypeFromFile(string $absolutePath): ?string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if (! $finfo) {
            return null;
        }

        $mimeType = finfo_file($finfo, $absolutePath) ?: null;
        finfo_close($finfo);

        return $mimeType && $mimeType !== 'application/octet-stream' ? $mimeType : null;
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

        if (! $this->memoryGuard->canGenerate($originalWidth, $originalHeight, $thumbnailWidth, $thumbnailHeight)) {
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

        $saved = match ($thumbnailExtension) {
            'jpg' => imagejpeg($thumbnail, $tempFile, 85),
            'png' => imagepng($thumbnail, $tempFile, 6),
            default => false,
        };

        imagedestroy($thumbnail);

        return $saved && file_exists($tempFile)
            ? $this->storeTempThumbnail($disk, $thumbnailPath, $tempFile)
            : null;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function resolveThumbnailDimensions(int $originalWidth, int $originalHeight): array
    {
        $targetWidth = 450;

        if ($originalWidth <= $targetWidth) {
            return [$originalWidth, $originalHeight];
        }

        $aspectRatio = $originalWidth / $originalHeight;

        return [$targetWidth, (int) round($targetWidth / $aspectRatio)];
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

        $listing = is_array($file->listing_metadata) ? $file->listing_metadata : [];
        $listing['width'] = $width;
        $listing['height'] = $height;
        $file->listing_metadata = $listing;

        $meta = FileMetadata::query()->firstOrNew(['file_id' => $file->id]);
        $payload = is_array($meta->payload) ? $meta->payload : [];

        $payload['width'] = $width;
        $payload['height'] = $height;

        $meta->payload = $payload;
        $meta->save();
    }
}
