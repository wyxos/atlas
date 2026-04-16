<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

final class AtlasPathResolver
{
    /**
     * @return list<string>
     */
    public static function preferredDiskNames(): array
    {
        return array_values(array_unique(array_filter([
            (string) config('downloads.disk'),
            'atlas',
        ], static fn (mixed $diskName): bool => is_string($diskName) && $diskName !== '')));
    }

    /**
     * @return array{disk_name:string|null, full_path:string, size:int|null}|null
     */
    public static function resolveExistingPath(?string $path, ?array $diskNames = null): ?array
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        foreach ($diskNames ?? self::preferredDiskNames() as $diskName) {
            try {
                $disk = Storage::disk($diskName);
                if (! $disk->exists($path)) {
                    continue;
                }

                $size = $disk->size($path);

                return [
                    'disk_name' => $diskName,
                    'full_path' => $disk->path($path),
                    'size' => is_int($size) ? $size : null,
                ];
            } catch (\Throwable) {
                // Keep falling through the disk list.
            }
        }

        $legacyPath = storage_path('app/'.$path);
        if (! is_file($legacyPath)) {
            return null;
        }

        $size = @filesize($legacyPath);

        return [
            'disk_name' => null,
            'full_path' => $legacyPath,
            'size' => is_int($size) ? $size : null,
        ];
    }

    public static function absolutePath(?string $path, ?array $diskNames = null): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        $resolved = self::resolveExistingPath($path, $diskNames);
        if ($resolved) {
            $normalized = realpath($resolved['full_path']);

            return $normalized !== false ? $normalized : $resolved['full_path'];
        }

        $preferredDiskNames = $diskNames ?? self::preferredDiskNames();
        foreach ($preferredDiskNames as $diskName) {
            try {
                return Storage::disk($diskName)->path($path);
            } catch (\Throwable) {
                // Keep falling through the disk list.
            }
        }

        $legacyPath = storage_path('app/'.$path);
        $normalized = realpath($legacyPath);

        return $normalized !== false ? $normalized : $legacyPath;
    }
}
