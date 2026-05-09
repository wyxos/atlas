<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class AtlasStorage
{
    public const string DISK = 'atlas';

    public const string DOWNLOADS = 'downloads';

    public const string IMPORTS = 'imports';

    /**
     * @return list<string>
     */
    public static function namespaces(): array
    {
        return [
            self::DOWNLOADS,
            self::IMPORTS,
        ];
    }

    public function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    public function rootPath(): string
    {
        return rtrim((string) config('atlas.root'), '\\/');
    }

    public function appRootPath(): string
    {
        return $this->rootPath().DIRECTORY_SEPARATOR.'.app';
    }

    public function ensureManagedDirectories(): void
    {
        $disk = $this->disk();

        foreach (self::namespaces() as $namespace) {
            if (! $disk->exists($namespace)) {
                $disk->makeDirectory($namespace, 0755, true);
            }
        }
    }

    public function storedFilename(?string $filename, ?string $extension = null): string
    {
        $filename = $this->sanitizeFilename($filename);
        $extension = $this->normalizeExtension($extension);

        if ($extension === null) {
            return $filename;
        }

        $suffix = '.'.$extension;
        if (str_ends_with(strtolower($filename), $suffix)) {
            return $filename;
        }

        return $filename.$suffix;
    }

    public function randomStoredFilename(?string $extension = null): string
    {
        $extension = $this->normalizeExtension($extension) ?? 'bin';

        return Str::random(40).'.'.$extension;
    }

    public function filenameWithExtension(string $filename, string $extension): string
    {
        $filename = $this->sanitizeFilename($filename);
        $extension = $this->normalizeExtension($extension) ?? 'bin';
        $base = pathinfo($filename, PATHINFO_FILENAME) ?: 'file';

        return $base.'.'.$extension;
    }

    public function segmentedPath(string $namespace, string $filename, ?string $hash = null): string
    {
        $namespace = $this->normalizeNamespace($namespace);
        $filename = $this->sanitizeFilename($filename);
        $hash = $this->normalizeHash($hash) ?? hash('sha256', $filename);

        return "{$namespace}/".substr($hash, 0, 2).'/'.substr($hash, 2, 2)."/{$filename}";
    }

    public function derivedPath(string $sourcePath, string $directory, string $filename): string
    {
        return $this->derivedDirectory($sourcePath, $directory).'/'.$this->sanitizeFilename($filename);
    }

    public function derivedDirectory(string $sourcePath, string $directory): string
    {
        $sourcePath = trim(str_replace('\\', '/', $sourcePath), '/');
        $parent = trim(dirname($sourcePath), '. /');
        $directory = $this->sanitizeSegment($directory);

        if ($directory === '') {
            throw new \InvalidArgumentException('Derived directory is required.');
        }

        return $parent !== '' ? $parent.'/'.$directory : $directory;
    }

    public function uniqueSegmentedPath(Filesystem $disk, string $namespace, string $filename, ?string $hash = null): string
    {
        $filename = $this->sanitizeFilename($filename);
        $path = $this->segmentedPath($namespace, $filename, $hash);
        if (! $disk->exists($path)) {
            return $path;
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $base = pathinfo($filename, PATHINFO_FILENAME) ?: 'file';

        for ($suffix = 2; $suffix < 10000; $suffix++) {
            $candidateFilename = $extension !== ''
                ? "{$base}-{$suffix}.{$extension}"
                : "{$base}-{$suffix}";
            $candidatePath = $this->segmentedPath($namespace, $candidateFilename, $hash);

            if (! $disk->exists($candidatePath)) {
                return $candidatePath;
            }
        }

        return $this->segmentedPath($namespace, $base.'-'.Str::random(8).($extension !== '' ? ".{$extension}" : ''), $hash);
    }

    public function normalizeHash(?string $hash): ?string
    {
        if (! is_string($hash)) {
            return null;
        }

        $hash = strtolower(trim($hash));

        return preg_match('/^[a-f0-9]{4,}$/', $hash) === 1 ? $hash : null;
    }

    private function normalizeNamespace(string $namespace): string
    {
        $namespace = trim(str_replace('\\', '/', $namespace), '/');

        if (! in_array($namespace, self::namespaces(), true)) {
            throw new \InvalidArgumentException("Unsupported Atlas app storage namespace [{$namespace}].");
        }

        return $namespace;
    }

    private function sanitizeFilename(?string $filename): string
    {
        $filename = basename(str_replace('\\', '/', trim((string) $filename)));
        $filename = preg_replace('/[\x00-\x1F\x7F<>:"|?*]+/', '-', $filename) ?? '';
        $filename = preg_replace('/\s+/', ' ', $filename) ?? '';
        $filename = trim($filename, " .\t\n\r\0\x0B");

        return $filename !== '' ? mb_substr($filename, 0, 180) : Str::random(40).'.bin';
    }

    private function sanitizeSegment(string $segment): string
    {
        $segment = strtolower(trim($segment));
        $segment = preg_replace('/[^a-z0-9_-]+/', '-', $segment) ?? '';

        return trim($segment, '-_');
    }

    private function normalizeExtension(?string $extension): ?string
    {
        if (! is_string($extension)) {
            return null;
        }

        $extension = strtolower(trim($extension, ". \t\n\r\0\x0B"));
        $extension = preg_replace('/[^a-z0-9]+/', '', $extension) ?? '';

        return $extension !== '' ? $extension : null;
    }
}
