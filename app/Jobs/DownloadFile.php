<?php

namespace App\Jobs;

use App\Models\File;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DownloadFile implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $fileId
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $file = File::find($this->fileId);

        if (! $file || ! $file->url) {
            \Log::info('DownloadFile job skipped: file not found or no URL', [
                'file_id' => $this->fileId,
                'file_exists' => $file !== null,
                'has_url' => $file?->url !== null,
            ]);

            return;
        }

        // Skip if file is already downloaded and has a path
        if ($file->downloaded && ! empty($file->path)) {
            \Log::info('DownloadFile job skipped: file already downloaded', [
                'file_id' => $this->fileId,
                'downloaded' => $file->downloaded,
                'path' => $file->path,
            ]);

            return;
        }

        try {
            // Download the file from URL
            $response = Http::timeout(30)->get($file->url);

            if (! $response->successful()) {
                return;
            }

            $fileContents = $response->body();

            // Determine file extension: prefer MIME type from content, then URL, then fallback
            $extension = $file->ext
                ?? $this->getExtensionFromMimeType($fileContents, $response->header('Content-Type'))
                ?? $this->getExtensionFromUrl($file->url)
                ?? 'bin';

            // Generate filename if not set, or ensure it has the correct extension
            if (empty($file->filename)) {
                $filename = Str::random(40).'.'.$extension;
            } else {
                // If filename exists but doesn't have the correct extension, update it
                $currentExt = pathinfo($file->filename, PATHINFO_EXTENSION);
                if (empty($currentExt) || strtolower($currentExt) !== $extension) {
                    $baseName = pathinfo($file->filename, PATHINFO_FILENAME);
                    $filename = $baseName.'.'.$extension;
                } else {
                    $filename = $file->filename;
                }
            }

            // Generate hash for segmentation (use file hash if available, otherwise generate from filename)
            // This ensures consistent placement - same file always goes to same location
            $hashForSegmentation = $file->hash ?? hash('sha256', $filename);

            // Generate segmented storage path to avoid I/O issues with too many files in one folder
            // Uses same approach as File::generateStoragePath - 2-level hash-based segmentation
            $storagePath = $this->generateSegmentedPath('downloads', $filename, $hashForSegmentation);

            // Ensure directory exists
            $disk = Storage::disk('atlas-app');
            $directory = dirname($storagePath);
            if (! $disk->exists($directory)) {
                $disk->makeDirectory($directory, 0755, true);
            }

            // Store the file
            $disk->put($storagePath, $fileContents);

            // Update file record
            $updates = [
                'path' => $storagePath,
                'filename' => $filename,
                'downloaded' => true,
                'downloaded_at' => now(),
            ];

            // Generate thumbnail for images
            $mimeType = $this->getMimeTypeFromContent($fileContents, $response->header('Content-Type'));
            if ($this->isImageMimeType($mimeType)) {
                $thumbnailPath = $this->generateThumbnail($fileContents, $filename, $hashForSegmentation, $disk);
                if ($thumbnailPath) {
                    $updates['thumbnail_path'] = $thumbnailPath;
                }
            }

            // Clear blacklist flags if file was blacklisted (race condition handling)
            if ($file->blacklisted_at !== null) {
                $updates['blacklisted_at'] = null;
                $updates['blacklist_reason'] = null;
            }

            $file->update($updates);

            \Log::info('DownloadFile job completed successfully', [
                'file_id' => $this->fileId,
                'path' => $storagePath,
            ]);
        } catch (\Exception $e) {
            // Log error but don't throw - job can be retried
            \Log::error('Failed to download file', [
                'file_id' => $this->fileId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Extract file extension from URL.
     */
    private function getExtensionFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if ($path) {
            $extension = pathinfo($path, PATHINFO_EXTENSION);
            if ($extension) {
                return strtolower($extension);
            }
        }

        return null;
    }

    /**
     * Determine file extension from MIME type detected from file content.
     * Uses finfo to detect MIME type from actual file content (magic bytes),
     * which is more reliable than URL parsing.
     */
    private function getExtensionFromMimeType(string $fileContents, ?string $contentTypeHeader = null): ?string
    {
        // First, try to use Content-Type header if provided and valid
        if ($contentTypeHeader) {
            $mimeType = $this->extractMimeTypeFromHeader($contentTypeHeader);
            if ($mimeType && $mimeType !== 'application/octet-stream') {
                $extension = $this->mimeTypeToExtension($mimeType);
                if ($extension) {
                    return $extension;
                }
            }
        }

        // Use finfo to detect MIME type from file content (magic bytes)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mimeType = finfo_buffer($finfo, $fileContents);
            finfo_close($finfo);

            if ($mimeType && $mimeType !== 'application/octet-stream') {
                $extension = $this->mimeTypeToExtension($mimeType);
                if ($extension) {
                    return $extension;
                }
            }
        }

        return null;
    }

    /**
     * Extract MIME type from Content-Type header (removes charset, etc.).
     */
    private function extractMimeTypeFromHeader(string $contentType): ?string
    {
        // Content-Type header can be like "image/jpeg; charset=utf-8"
        $parts = explode(';', $contentType);
        $mimeType = trim($parts[0]);

        return ! empty($mimeType) ? $mimeType : null;
    }

    /**
     * Convert MIME type to file extension.
     */
    private function mimeTypeToExtension(string $mimeType): ?string
    {
        $mimeToExt = [
            // Images
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'image/bmp' => 'bmp',
            'image/x-icon' => 'ico',
            'image/tiff' => 'tiff',
            'image/avif' => 'avif',
            'image/heic' => 'heic',
            'image/heif' => 'heif',

            // Videos
            'video/mp4' => 'mp4',
            'video/mpeg' => 'mpeg',
            'video/quicktime' => 'mov',
            'video/x-msvideo' => 'avi',
            'video/x-ms-wmv' => 'wmv',
            'video/webm' => 'webm',
            'video/ogg' => 'ogv',
            'video/x-matroska' => 'mkv',
            'video/x-flv' => 'flv',

            // Audio
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'audio/x-m4a' => 'm4a',
            'audio/ogg' => 'ogg',
            'audio/wav' => 'wav',
            'audio/x-wav' => 'wav',
            'audio/webm' => 'weba',
            'audio/flac' => 'flac',
            'audio/aac' => 'aac',

            // Documents
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/zip' => 'zip',
            'application/x-rar-compressed' => 'rar',
            'application/x-7z-compressed' => '7z',
            'application/x-tar' => 'tar',
            'application/gzip' => 'gz',

            // Text
            'text/plain' => 'txt',
            'text/html' => 'html',
            'text/css' => 'css',
            'text/javascript' => 'js',
            'application/json' => 'json',
            'application/xml' => 'xml',
            'text/xml' => 'xml',
        ];

        return $mimeToExt[strtolower($mimeType)] ?? null;
    }

    /**
     * Generate a segmented storage path to avoid I/O issues with too many files.
     *
     * Uses hash-based subfolder structure: {base}/{hash[0:2]}/{hash[2:4]}/{filename}
     * This creates 65,536 possible folders (256^2), distributing files evenly.
     *
     * This approach is recommended because:
     * - Even distribution (avoids hot spots)
     * - Consistent placement (same hash = same location)
     * - Predictable structure
     * - Good performance for large file volumes
     *
     * Similar to File::generateStoragePath() but for atlas-app disk structure.
     */
    private function generateSegmentedPath(string $base, string $filename, string $hash): string
    {
        // Take first 4 characters for 2-level subfolder structure
        // This distributes files evenly and keeps folder sizes manageable
        $subfolder1 = substr($hash, 0, 2);
        $subfolder2 = substr($hash, 2, 2);

        return "{$base}/{$subfolder1}/{$subfolder2}/{$filename}";
    }

    /**
     * Get MIME type from file content or header.
     */
    private function getMimeTypeFromContent(string $fileContents, ?string $contentTypeHeader = null): ?string
    {
        // First, try Content-Type header
        if ($contentTypeHeader) {
            $mimeType = $this->extractMimeTypeFromHeader($contentTypeHeader);
            if ($mimeType && $mimeType !== 'application/octet-stream') {
                return $mimeType;
            }
        }

        // Use finfo to detect MIME type from file content
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mimeType = finfo_buffer($finfo, $fileContents);
            finfo_close($finfo);

            if ($mimeType && $mimeType !== 'application/octet-stream') {
                return $mimeType;
            }
        }

        return null;
    }

    /**
     * Check if MIME type is an image.
     */
    private function isImageMimeType(?string $mimeType): bool
    {
        if (! $mimeType) {
            return false;
        }

        return str_starts_with(strtolower($mimeType), 'image/') &&
            ! in_array(strtolower($mimeType), ['image/svg+xml', 'image/x-icon']); // Skip SVG and ICO
    }

    /**
     * Generate thumbnail for image file.
     * Maintains aspect ratio, resizes to at least width 450px (or proportional height).
     */
    private function generateThumbnail(string $fileContents, string $filename, string $hash, $disk): ?string
    {
        try {
            // Create image resource from file contents
            $image = @imagecreatefromstring($fileContents);
            if (! $image) {
                \Log::warning('Failed to create image resource for thumbnail', [
                    'file_id' => $this->fileId,
                    'filename' => $filename,
                ]);

                return null;
            }

            // Get original dimensions
            $originalWidth = imagesx($image);
            $originalHeight = imagesy($image);

            if ($originalWidth <= 0 || $originalHeight <= 0) {
                imagedestroy($image);
                \Log::warning('Invalid image dimensions', [
                    'file_id' => $this->fileId,
                    'width' => $originalWidth,
                    'height' => $originalHeight,
                ]);

                return null;
            }

            // Calculate thumbnail dimensions maintaining aspect ratio
            // Target: at least 450px width, height proportional
            $targetWidth = 450;
            $aspectRatio = $originalWidth / $originalHeight;

            if ($originalWidth <= $targetWidth) {
                // Image is already small enough, use original dimensions
                $thumbnailWidth = $originalWidth;
                $thumbnailHeight = $originalHeight;
            } else {
                // Resize to target width, maintain aspect ratio
                $thumbnailWidth = $targetWidth;
                $thumbnailHeight = (int) round($targetWidth / $aspectRatio);
            }

            // Create thumbnail image
            $thumbnail = imagecreatetruecolor($thumbnailWidth, $thumbnailHeight);

            // Preserve transparency for PNG and GIF
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($extension, ['png', 'gif', 'webp'])) {
                imagealphablending($thumbnail, false);
                imagesavealpha($thumbnail, true);
                $transparent = imagecolorallocatealpha($thumbnail, 0, 0, 0, 127);
                imagefill($thumbnail, 0, 0, $transparent);
            }

            // Resize image
            imagecopyresampled(
                $thumbnail,
                $image,
                0, 0, 0, 0,
                $thumbnailWidth,
                $thumbnailHeight,
                $originalWidth,
                $originalHeight
            );

            // Generate thumbnail filename
            $thumbnailFilename = pathinfo($filename, PATHINFO_FILENAME).'_thumb.'.pathinfo($filename, PATHINFO_EXTENSION);

            // Generate segmented thumbnail path
            $thumbnailPath = $this->generateSegmentedPath('thumbnails', $thumbnailFilename, $hash);

            // Ensure thumbnail directory exists
            $thumbnailDirectory = dirname($thumbnailPath);
            if (! $disk->exists($thumbnailDirectory)) {
                $disk->makeDirectory($thumbnailDirectory, 0755, true);
            }

            // Save thumbnail to temporary file first
            $tempFile = tempnam(sys_get_temp_dir(), 'thumb_');
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

            // Clean up image resources
            imagedestroy($image);
            imagedestroy($thumbnail);

            if (! $saved || ! file_exists($tempFile)) {
                \Log::warning('Failed to save thumbnail', [
                    'file_id' => $this->fileId,
                    'extension' => $extension,
                ]);

                return null;
            }

            // Store thumbnail in disk
            $thumbnailContents = file_get_contents($tempFile);
            $disk->put($thumbnailPath, $thumbnailContents);

            // Clean up temp file
            @unlink($tempFile);

            \Log::info('Thumbnail generated successfully', [
                'file_id' => $this->fileId,
                'thumbnail_path' => $thumbnailPath,
                'original_size' => "{$originalWidth}x{$originalHeight}",
                'thumbnail_size' => "{$thumbnailWidth}x{$thumbnailHeight}",
            ]);

            return $thumbnailPath;
        } catch (\Exception $e) {
            \Log::error('Failed to generate thumbnail', [
                'file_id' => $this->fileId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
