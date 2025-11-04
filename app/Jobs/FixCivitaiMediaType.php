<?php

namespace App\Jobs;

use App\Models\File;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\MimeTypes;

class FixCivitaiMediaType implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $fileId) {}

    public function handle(): void
    {
        $file = File::find($this->fileId);
        if (! $file) {
            return;
        }

        if (strcasecmp((string) $file->source, 'CivitAI') !== 0) {
            return;
        }

        if (! str_contains((string) $file->url, '.mp4')) {
            return;
        }

        $path = (string) ($file->path ?? '');
        if ($path === '') {
            return;
        }

        $diskPaths = $this->diskPathMap($path);
        if ($diskPaths === []) {
            return;
        }

        $sanitizedPath = $this->sanitizePath($path);
        if ($sanitizedPath !== $path) {
            foreach ($diskPaths as $disk => $currentPath) {
                if ($currentPath === $sanitizedPath) {
                    continue;
                }

                $this->ensureDirectoryExists($disk, $sanitizedPath);
                Storage::disk($disk)->move($currentPath, $sanitizedPath);
                $diskPaths[$disk] = $sanitizedPath;
            }

            $file->forceFill(['path' => $sanitizedPath])->saveQuietly();
            $path = $sanitizedPath;
        }

        $primaryDisk = array_key_first($diskPaths);
        if ($primaryDisk === null) {
            return;
        }

        $primaryPath = $diskPaths[$primaryDisk];
        $mime = $this->detectMimeFromDisk($primaryDisk, $primaryPath, (string) $file->mime_type);

        if (! $mime) {
            return;
        }

        $extension = $this->extensionFromMime($mime);
        if (! $extension) {
            return;
        }

        $filename = $file->filename ? $this->sanitizeFilename($file->filename) : basename($path);
        if ($filename === '') {
            $filename = basename($path);
        }

        $currentExt = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $base = $currentExt !== '' ? Str::beforeLast($filename, '.'.$currentExt) : $filename;

        $newFilename = $currentExt === $extension ? $filename : $base.'.'.$extension;
        $newPath = 'downloads/'.$newFilename;

        if ($newPath !== $path) {
            foreach ($diskPaths as $disk => $currentPath) {
                if ($currentPath === $newPath) {
                    continue;
                }

                $this->ensureDirectoryExists($disk, $newPath);
                Storage::disk($disk)->move($currentPath, $newPath);
                $diskPaths[$disk] = $newPath;
            }

            $path = $newPath;
        }

        $file->forceFill([
            'filename' => $newFilename,
            'path' => $path,
            'mime_type' => $mime,
        ])->saveQuietly();

        try {
            $file->searchable();
        } catch (\Throwable $e) {
            // ignore indexing errors
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
