<?php

namespace App\Jobs;

use App\Models\File;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\MimeTypes;

class FixCivitaiMediaType implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public ?int $fileId = null) {}

    public function handle(): void
    {
        if ($this->fileId === null) {
            File::query()
                ->where('source', 'CivitAI')
                ->orderBy('id')
                ->chunkById(200, function ($files) {
                    foreach ($files as $file) {
                        $this->processFile($file);
                    }
                });

            return;
        }

        $file = File::find($this->fileId);
        if (! $file) {
            return;
        }

        $this->processFile($file);
    }

    protected function processFile(File $file): void
    {
        if (strcasecmp((string) $file->source, 'CivitAI') !== 0) {
            return;
        }

        $path = (string) ($file->path ?? '');
        if ($path === '') {
            return;
        }

        // Verify this file matches the criteria: thumbnail_url contains mp4 and mime_type is webp
        $thumbnailUrl = (string) ($file->thumbnail_url ?? '');
        $mimeType = (string) ($file->mime_type ?? '');

        if (! str_contains($thumbnailUrl, 'mp4') || $mimeType !== 'image/webp') {
            return;
        }

        // This is a video file that was incorrectly stored as a webp poster
        // Fetch the real video URL from the referrer page
        $videoUrl = $this->extractVideoUrlFromFile($file->id);
        if (! $videoUrl) {
            return;
        }

        // Download the real video file
        $diskPaths = $this->diskPathMap($path);
        if ($diskPaths === []) {
            return;
        }

        $primaryDisk = array_key_first($diskPaths);
        if ($primaryDisk === null) {
            return;
        }

        // Download the video to a temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'civitai_video_');
        if ($tempFile === false) {
            return;
        }

        try {
            $response = Http::timeout(300)->get($videoUrl);
            if (! $response->successful()) {
                return;
            }

            file_put_contents($tempFile, $response->body());

            // Verify it's actually a video file
            $detectedMime = $this->detectMimeFromFile($tempFile);
            if (! $detectedMime || ! str_starts_with($detectedMime, 'video/')) {
                @unlink($tempFile);

                return;
            }

            // Determine new filename with .mp4 extension
            $filename = $file->filename ? $this->sanitizeFilename($file->filename) : basename($path);
            if ($filename === '') {
                $filename = basename($path);
            }

            $currentExt = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
            $base = $currentExt !== '' ? Str::beforeLast($filename, '.'.$currentExt) : $filename;
            $newFilename = $base.'.mp4';
            $newPath = 'downloads/'.$newFilename;

            // Delete old file from all disks
            foreach ($diskPaths as $disk => $currentPath) {
                try {
                    Storage::disk($disk)->delete($currentPath);
                } catch (\Throwable $e) {
                    // ignore deletion errors
                }
            }

            // Store new video file
            $this->ensureDirectoryExists($primaryDisk, $newPath);
            Storage::disk($primaryDisk)->put($newPath, file_get_contents($tempFile));

            // Update file record
            $file->forceFill([
                'filename' => $newFilename,
                'path' => $newPath,
                'mime_type' => 'video/mp4',
                'ext' => 'mp4',
                'url' => $videoUrl, // Update URL to the real video URL
                'not_found' => false,
                'size' => $this->fileSize($primaryDisk, $newPath),
            ])->saveQuietly();

            try {
                $file->searchable();
            } catch (\Throwable $e) {
                // ignore indexing errors
            }
        } finally {
            @unlink($tempFile);
        }
    }

    protected function extractVideoUrlFromFile(int $fileId): ?string
    {
        $file = File::find($fileId);
        if (! $file) {
            return null;
        }

        $referrerUrl = (string) ($file->referrer_url ?? '');
        if ($referrerUrl === '') {
            return null;
        }

        return $this->extractVideoUrlFromReferrer($referrerUrl);
    }

    protected function extractVideoUrlFromReferrer(string $referrerUrl): ?string
    {
        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                ])
                ->get($referrerUrl);

            if (! $response->successful()) {
                return null;
            }

            $html = $response->body();
            $referrerBase = $this->getBaseUrl($referrerUrl);

            // Look for <source> tags with mp4 files
            // Pattern: <source src="..." ...> or <source ... src="...">
            if (preg_match_all('/<source[^>]+src=["\']([^"\']+\.mp4[^"\']*)["\'][^>]*>/i', $html, $matches)) {
                $foundUrls = [];
                foreach ($matches[1] as $url) {
                    // Resolve relative URLs
                    $absoluteUrl = $this->resolveUrl($url, $referrerBase);
                    $foundUrls[] = $absoluteUrl;

                    // Prefer URLs with transcode parameters (higher quality)
                    if (str_contains($absoluteUrl, 'transcode=true')) {
                        return $absoluteUrl;
                    }
                }

                // Fall back to first mp4 URL found
                return $foundUrls[0] ?? null;
            }

            // Also check for video tags with source children
            if (preg_match_all('/<video[^>]*>(.*?)<\/video>/is', $html, $videoMatches)) {
                $foundUrls = [];
                foreach ($videoMatches[1] as $videoContent) {
                    if (preg_match('/<source[^>]+src=["\']([^"\']+\.mp4[^"\']*)["\'][^>]*>/i', $videoContent, $srcMatches)) {
                        $absoluteUrl = $this->resolveUrl($srcMatches[1], $referrerBase);
                        $foundUrls[] = $absoluteUrl;

                        if (str_contains($absoluteUrl, 'transcode=true')) {
                            return $absoluteUrl;
                        }
                    }
                }

                // Fall back to first found URL
                if (! empty($foundUrls)) {
                    return $foundUrls[0];
                }
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function getBaseUrl(string $url): string
    {
        $parsed = parse_url($url);
        if (! $parsed) {
            return $url;
        }

        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';

        return $scheme.'://'.$host;
    }

    protected function resolveUrl(string $url, string $baseUrl): string
    {
        // If already absolute, return as-is
        if (preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }

        // Resolve relative URL
        $parsedBase = parse_url($baseUrl);
        if (! $parsedBase) {
            return $url;
        }

        $scheme = $parsedBase['scheme'] ?? 'https';
        $host = $parsedBase['host'] ?? '';

        // If URL starts with /, it's absolute path
        if (str_starts_with($url, '/')) {
            return $scheme.'://'.$host.$url;
        }

        // Relative path - need to resolve against base path
        $basePath = $parsedBase['path'] ?? '/';
        $baseDir = dirname($basePath);
        if ($baseDir === '.') {
            $baseDir = '/';
        }

        return $scheme.'://'.$host.rtrim($baseDir, '/').'/'.ltrim($url, '/');
    }

    protected function detectMimeFromFile(string $filePath): ?string
    {
        if (! file_exists($filePath)) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }

        $mime = finfo_file($finfo, $filePath) ?: null;
        finfo_close($finfo);

        return $mime;
    }

    protected function fileSize(string $disk, string $path): ?int
    {
        try {
            return Storage::disk($disk)->size($path);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function sanitizePath(string $path): string
    {
        $withoutQuery = Str::before($path, '?');
        $basename = basename($withoutQuery);

        return 'downloads/'.$basename;
    }

    protected function sanitizeFilename(string $filename): string
    {
        $clean = Str::before($filename, '?');

        return basename($clean);
    }

    protected function detectMimeFromDisk(string $diskName, string $path, ?string $fallback): ?string
    {
        $disk = Storage::disk($diskName);

        $stream = $disk->readStream($path);
        if (! $stream) {
            return $fallback;
        }

        try {
            $buffer = stream_get_contents($stream, 1024 * 1024);
        } finally {
            fclose($stream);
        }

        if ($buffer === false) {
            return $fallback;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return $fallback;
        }

        $mime = finfo_buffer($finfo, $buffer) ?: null;
        finfo_close($finfo);

        return $mime ?: $fallback;
    }

    protected function extensionFromMime(string $mime): ?string
    {
        $mime = strtolower(trim($mime));
        $extensions = MimeTypes::getDefault()->getExtensions($mime);

        return $extensions[0] ?? match ($mime) {
            'image/webp' => 'webp',
            default => null,
        };
    }

    protected function diskPathMap(string $originalPath): array
    {
        $candidates = array_unique([
            $originalPath,
            $this->sanitizePath($originalPath),
        ]);

        $map = [];

        foreach (['atlas_app', 'atlas'] as $disk) {
            $storage = Storage::disk($disk);
            foreach ($candidates as $candidate) {
                if ($storage->exists($candidate)) {
                    $map[$disk] = $candidate;
                    break;
                }
            }
        }

        return $map;
    }

    protected function ensureDirectoryExists(string $disk, string $path): void
    {
        $directory = Str::contains($path, '/') ? Str::beforeLast($path, '/') : null;

        if (! $directory) {
            return;
        }

        $storage = Storage::disk($disk);

        if (! $storage->exists($directory)) {
            $storage->makeDirectory($directory);
        }
    }
}
