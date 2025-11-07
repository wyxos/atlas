<?php

namespace App\Jobs;

use App\Models\File;
use App\Support\CivitaiVideoUrlExtractor;
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

        // Verify this file matches the criteria: url or thumbnail_url contains mp4 and mime_type is webp
        // OR: if mime_type is already video/mp4 but path still has .webp extension, we need to fix it
        $thumbnailUrl = (string) ($file->thumbnail_url ?? '');
        $url = (string) ($file->url ?? '');
        $mimeType = (string) ($file->mime_type ?? '');
        $currentExt = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        // Check if this needs fixing:
        // 1. (url OR thumbnail_url) contains mp4 AND mime_type is webp (original problematic case)
        // 2. OR mime_type is video/mp4 but path extension is still .webp (partially fixed case)
        $needsFix = false;
        $urlContainsMp4 = str_contains(strtolower($url), 'mp4');
        $thumbnailContainsMp4 = str_contains(strtolower($thumbnailUrl), 'mp4');
        if (($urlContainsMp4 || $thumbnailContainsMp4) && $mimeType === 'image/webp') {
            $needsFix = true;
        } elseif ($mimeType === 'video/mp4' && $currentExt === 'webp') {
            // Already partially fixed (mime_type updated) but path wasn't
            $needsFix = true;
        }

        if (! $needsFix) {
            return;
        }

        // This is a video file that was incorrectly stored as a webp poster
        // Fetch the real video URL from the referrer page
        $extractor = new CivitaiVideoUrlExtractor;
        $videoUrl = $extractor->extractFromFileId($file->id);

        // If referrer URL returns 404, sniff bytes from original URL and thumbnail_url
        $shouldReDownload = false;
        if ($videoUrl === '404_NOT_FOUND') {
            $currentUrl = (string) ($file->url ?? '');
            $thumbnailUrl = (string) ($file->thumbnail_url ?? '');

            // Try original URL first
            $originalIsVideo = false;
            if ($currentUrl !== '') {
                $detectedMime = $this->sniffUrlMimeType($currentUrl);
                if ($detectedMime && str_starts_with($detectedMime, 'video/')) {
                    $videoUrl = $currentUrl;
                    $shouldReDownload = true; // Need to re-download to fix mime type
                    $originalIsVideo = true;
                }
            }

            // If original URL is not a video (even if it's an image), try thumbnail_url
            if (! $originalIsVideo && $thumbnailUrl !== '') {
                $detectedMime = $this->sniffUrlMimeType($thumbnailUrl);
                if ($detectedMime && str_starts_with($detectedMime, 'video/')) {
                    $videoUrl = $thumbnailUrl;
                    $shouldReDownload = true; // Need to re-download to fix mime type
                }
            }

            // If neither is a video, leave as is (it's a static image)
            if ($videoUrl === '404_NOT_FOUND') {
                return;
            }
        } elseif (! $videoUrl) {
            return;
        }

        // If the extracted URL is the same as the current URL and we don't need to re-download, skip
        $currentUrl = (string) ($file->url ?? '');
        if ($videoUrl === $currentUrl && ! $shouldReDownload) {
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

            // Determine new filename with .mp4 extension
            // Use the existing path's basename but change extension to .mp4
            $pathBasename = basename($path);
            $pathExt = strtolower((string) pathinfo($pathBasename, PATHINFO_EXTENSION));

            // Remove current extension and add .mp4
            if ($pathExt !== '' && $pathExt !== 'mp4') {
                $base = Str::beforeLast($pathBasename, '.'.$pathExt);
            } else {
                $base = $pathExt === 'mp4' ? Str::beforeLast($pathBasename, '.mp4') : $pathBasename;
            }

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

    protected function extensionFromMime(string $mime): ?string
    {
        $mime = strtolower(trim($mime));
        $extensions = MimeTypes::getDefault()->getExtensions($mime);

        return $extensions[0] ?? match ($mime) {
            'image/webp' => 'webp',
            default => null,
        };
    }

    protected function sniffUrlMimeType(string $url): ?string
    {
        try {
            $tempFile = tempnam(sys_get_temp_dir(), 'sniff_');
            if ($tempFile === false) {
                return null;
            }

            try {
                // Try Range request first (more efficient)
                $response = Http::timeout(30)
                    ->withHeaders(['Range' => 'bytes=0-1048575'])
                    ->get($url);

                if ($response->status() === 404) {
                    return null;
                }

                // If Range not supported, try full request
                if ($response->status() === 200 || $response->status() === 206) {
                    $body = $response->body();
                    if (empty($body)) {
                        return null;
                    }

                    file_put_contents($tempFile, $body);

                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    if ($finfo === false) {
                        return null;
                    }

                    $mime = finfo_file($finfo, $tempFile) ?: null;
                    finfo_close($finfo);

                    return $mime;
                }

                // If Range request failed, try without Range
                $response = Http::timeout(30)->get($url);
                if ($response->status() === 404 || ! $response->successful()) {
                    return null;
                }

                $body = $response->body();
                if (empty($body)) {
                    return null;
                }

                // Limit to first 1MB for sniffing
                $sniffBody = substr($body, 0, 1048576);
                file_put_contents($tempFile, $sniffBody);

                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo === false) {
                    return null;
                }

                $mime = finfo_file($finfo, $tempFile) ?: null;
                finfo_close($finfo);

                return $mime;
            } finally {
                @unlink($tempFile);
            }
        } catch (\Throwable $e) {
            return null;
        }
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
