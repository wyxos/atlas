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
            $file = $transfer->relationLoaded('file') ? $transfer->file : $transfer->file()->first();
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

        return [
            'path' => $file?->path,
            'original' => $original,
            'preview' => $preview,
            'size' => $file?->size,
            'filename' => $file?->filename,
        ];
    }
}
