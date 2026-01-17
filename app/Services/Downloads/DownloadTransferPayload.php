<?php

namespace App\Services\Downloads;

use App\Models\DownloadTransfer;
use App\Models\File;

final class DownloadTransferPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function forList(DownloadTransfer $transfer): array
    {
        return [
            'id' => $transfer->id,
            'status' => $transfer->status,
            'queued_at' => $transfer->queued_at?->toISOString(),
            'started_at' => $transfer->started_at?->toISOString(),
            'finished_at' => $transfer->finished_at?->toISOString(),
            'failed_at' => $transfer->failed_at?->toISOString(),
            'percent' => (int) ($transfer->last_broadcast_percent ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forDetails(DownloadTransfer $transfer): array
    {
        return [
            'id' => $transfer->id,
            ...self::filePayload($transfer->relationLoaded('file') ? $transfer->file : $transfer->file()->first(), $transfer->url),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forQueued(DownloadTransfer $transfer): array
    {
        return [
            ...self::forList($transfer),
            ...self::filePayload($transfer->relationLoaded('file') ? $transfer->file : $transfer->file()->first(), $transfer->url),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forProgress(DownloadTransfer $transfer, int $percent): array
    {
        $payload = [
            'downloadTransferId' => $transfer->id,
            'status' => $transfer->status,
            'percent' => $percent,
            'started_at' => $transfer->started_at?->toISOString(),
            'finished_at' => $transfer->finished_at?->toISOString(),
            'failed_at' => $transfer->failed_at?->toISOString(),
        ];

        if ($transfer->isTerminal()) {
            $file = $transfer->file()->first();
            if ($file || $transfer->url) {
                $payload = [
                    ...$payload,
                    ...self::filePayload($file, $transfer->url),
                ];
            }
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private static function filePayload(?File $file, ?string $sourceUrl): array
    {
        $original = $sourceUrl;
        if (! $original && $file?->url) {
            $original = $file->url;
        }
        if (! $original && $file?->path) {
            $original = route('api.files.serve', ['file' => $file->id]);
        }

        $preview = $file?->thumbnail_url ?? $original;
        $plannedPath = self::plannedPath($file, $sourceUrl);

        return [
            'path' => $file?->path ?? $plannedPath,
            'original' => $original,
            'preview' => $preview,
            'size' => $file?->size,
            'filename' => $file?->filename,
        ];
    }

    private static function plannedPath(?File $file, ?string $sourceUrl): ?string
    {
        if (! $file || $file->path || ! $file->filename) {
            return null;
        }

        $extension = $file->ext ?? self::extensionFromUrl($sourceUrl) ?? 'bin';
        $storedFilename = self::storedFilename($file->filename, $extension);
        $hash = self::normalizeHash($file->hash) ?? hash('sha256', $storedFilename);
        $subfolder1 = substr($hash, 0, 2);
        $subfolder2 = substr($hash, 2, 2);

        return "downloads/{$subfolder1}/{$subfolder2}/{$storedFilename}";
    }

    private static function storedFilename(string $baseFilename, string $extension): string
    {
        $suffix = '.'.strtolower($extension);
        if ($suffix !== '.' && str_ends_with(strtolower($baseFilename), $suffix)) {
            return $baseFilename;
        }

        return $baseFilename.$suffix;
    }

    private static function normalizeHash(?string $hash): ?string
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

    private static function extensionFromUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (! $path) {
            return null;
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return $extension !== '' ? strtolower($extension) : null;
    }
}
