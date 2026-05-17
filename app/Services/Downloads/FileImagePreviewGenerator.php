<?php

namespace App\Services\Downloads;

use App\Models\File;
use App\Models\FileMetadata;
use App\Support\AtlasStorage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class FileImagePreviewGenerator
{
    public function __construct(
        private readonly FileThumbnailMemoryGuard $memoryGuard,
        private readonly AtlasStorage $appStorage,
    ) {}

    public function generateFromFile(Filesystem $disk, string $absolutePath, string $sourcePath): ?string
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

        [$previewWidth, $previewHeight] = $this->resolvePreviewDimensions($originalWidth, $originalHeight);

        $imageType = $this->detectImageType($absolutePath);
        $previewPath = $this->outputPath($absolutePath, $sourcePath, $imageType);
        $previewExtension = pathinfo($previewPath, PATHINFO_EXTENSION) ?: 'jpg';

        $previewDirectory = dirname($previewPath);
        if (! $disk->exists($previewDirectory)) {
            $disk->makeDirectory($previewDirectory, 0755, true);
        }

        $previewPathFromProcess = $this->generateImagePreviewWithFfmpeg(
            $disk,
            $absolutePath,
            $previewPath,
            $previewWidth,
            $previewHeight,
            $previewExtension,
        );
        if ($previewPathFromProcess) {
            return $previewPathFromProcess;
        }

        if (! $this->memoryGuard->canGenerate($originalWidth, $originalHeight, $previewWidth, $previewHeight)) {
            return null;
        }

        $image = $this->createImageResourceFromFile($absolutePath, $imageType);
        if (! $image) {
            return null;
        }

        $preview = imagecreatetruecolor($previewWidth, $previewHeight);

        if ($previewExtension === 'png') {
            imagealphablending($preview, false);
            imagesavealpha($preview, true);
            $transparent = imagecolorallocatealpha($preview, 0, 0, 0, 127);
            imagefill($preview, 0, 0, $transparent);
        }

        imagecopyresampled(
            $preview,
            $image,
            0,
            0,
            0,
            0,
            $previewWidth,
            $previewHeight,
            $originalWidth,
            $originalHeight
        );

        imagedestroy($image);

        $tempFile = tempnam(sys_get_temp_dir(), 'preview_');
        if (! $tempFile) {
            imagedestroy($preview);

            return null;
        }

        $saved = match ($previewExtension) {
            'jpg' => imagejpeg($preview, $tempFile, 85),
            'png' => imagepng($preview, $tempFile, 6),
            default => false,
        };

        imagedestroy($preview);

        return $saved && file_exists($tempFile)
            ? $this->storeTempPreview($disk, $previewPath, $tempFile)
            : null;
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function resolvePreviewDimensions(int $originalWidth, int $originalHeight): array
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

    private function resolveImagePreviewOutputExtension(int|false $imageType): string
    {
        if ($imageType === IMAGETYPE_GIF) {
            return 'gif';
        }

        if (in_array($imageType, [IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return 'png';
        }

        if (defined('IMAGETYPE_AVIF') && $imageType === IMAGETYPE_AVIF) {
            return 'png';
        }

        return 'jpg';
    }

    public function outputPath(string $absolutePath, string $sourcePath, int|false|null $imageType = null): string
    {
        $imageType ??= $this->detectImageType($absolutePath);
        $previewExtension = $this->resolveImagePreviewOutputExtension($imageType);
        $previewFilename = $this->appStorage->filenameWithExtension(basename($sourcePath), $previewExtension);

        return $this->appStorage->derivedPath($sourcePath, 'preview', $previewFilename);
    }

    private function generateImagePreviewWithFfmpeg(
        Filesystem $disk,
        string $absolutePath,
        string $previewPath,
        int $previewWidth,
        int $previewHeight,
        string $previewExtension
    ): ?string {
        $ffmpegPath = $this->resolveFfmpegPath((string) config('downloads.ffmpeg_path'));
        if (! $ffmpegPath) {
            return null;
        }

        $baseTempFile = tempnam(sys_get_temp_dir(), 'preview_');
        if (! $baseTempFile) {
            return null;
        }

        @unlink($baseTempFile);
        $tempFile = $baseTempFile.'.'.$previewExtension;
        $timeout = (int) config('downloads.ffmpeg_timeout_seconds', 120);

        $processArgs = [
            $ffmpegPath,
            '-y',
            '-loglevel',
            'error',
            '-i',
            $absolutePath,
            '-vf',
            "scale={$previewWidth}:{$previewHeight}:force_original_aspect_ratio=decrease",
        ];

        if (! in_array($previewExtension, ['gif', 'webp'], true)) {
            $processArgs = [
                ...$processArgs,
                '-frames:v',
                '1',
                '-update',
                '1',
            ];
        }

        $process = new Process([...$processArgs, $tempFile]);
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

        return $this->storeTempPreview($disk, $previewPath, $tempFile);
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

    private function storeTempPreview(Filesystem $disk, string $previewPath, string $tempFile): ?string
    {
        $stream = fopen($tempFile, 'rb');
        if ($stream === false) {
            @unlink($tempFile);

            return null;
        }

        try {
            $stored = $disk->put($previewPath, $stream);
        } finally {
            fclose($stream);
            @unlink($tempFile);
        }

        return $stored ? $previewPath : null;
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

    public function persistDimensions(File $file, string $absolutePath): void
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
