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
            return;
        }

        // Skip if file is already downloaded and has a path
        if ($file->downloaded && ! empty($file->path)) {
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

            // Generate storage path in atlas-app/downloads directory
            $storagePath = 'downloads/'.$filename;

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

            // Clear blacklist flags if file was blacklisted (race condition handling)
            if ($file->blacklisted_at !== null) {
                $updates['blacklisted_at'] = null;
                $updates['blacklist_reason'] = null;
            }

            $file->update($updates);
        } catch (\Exception $e) {
            // Log error but don't throw - job can be retried
            \Log::error('Failed to download file', [
                'file_id' => $this->fileId,
                'error' => $e->getMessage(),
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
}
