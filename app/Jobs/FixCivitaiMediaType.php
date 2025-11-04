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

        $disk = Storage::disk('atlas_app');

        $path = (string) ($file->path ?? '');
        if ($path === '') {
            return;
        }

        $sanitizedPath = $this->sanitizePath($path);
        if ($sanitizedPath !== $path) {
            if ($disk->exists($path)) {
                $disk->move($path, $sanitizedPath);
            }

            $file->forceFill(['path' => $sanitizedPath])->saveQuietly();
            $path = $sanitizedPath;
        }

        if (! $disk->exists($path)) {
            return;
        }

        $contents = $disk->get($path);
        $mime = $this->detectMimeFromContents($contents, (string) $file->mime_type);

        if (! $mime || ! str_starts_with($mime, 'image/')) {
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
            if ($disk->exists($path)) {
                $disk->move($path, $newPath);
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

    protected function detectMimeFromContents(string $contents, ?string $fallback): ?string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return $fallback;
        }

        $mime = finfo_buffer($finfo, $contents) ?: null;
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
}

