<?php

namespace App\Services\Downloads;

use App\Enums\DownloadTransferStatus;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Support\FileApiPath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

final class DownloadTransferPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function forList(DownloadTransfer $transfer): array
    {
        return self::listPayload($transfer);
    }

    /**
     * @param  Collection<int, DownloadTransfer>  $transfers
     * @return Collection<int, array<string, mixed>>
     */
    public static function forListCollection(Collection $transfers): Collection
    {
        $lookups = DownloadTransferListFacts::buildListLookups($transfers);

        return $transfers->map(
            fn (DownloadTransfer $transfer): array => self::listPayload($transfer, $lookups)
        );
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
            ...self::listPayload($transfer),
            ...self::filePayload($transfer->relationLoaded('file') ? $transfer->file : $transfer->file()->first(), $transfer->url),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forProgress(DownloadTransfer $transfer, int $percent): array
    {
        $file = self::assetUrlFileForTransfer($transfer);
        $payload = [
            'id' => $transfer->id,
            'asset_url' => self::assetUrl($file, $transfer->url),
            'file' => self::atlasFilePayload($file),
            'fileId' => $transfer->file_id,
            'file_id' => $transfer->file_id,
            'status' => $transfer->status,
            'created_at' => $transfer->created_at?->toISOString(),
            'queued_at' => $transfer->queued_at?->toISOString(),
            'started_at' => $transfer->started_at?->toISOString(),
            'finished_at' => $transfer->finished_at?->toISOString(),
            'failed_at' => $transfer->failed_at?->toISOString(),
            'percent' => $percent,
            'error' => $transfer->error,
            ...DownloadTransferActionAvailability::forPayload($transfer),
            ...DownloadTransferListFacts::forTransfer($transfer),
        ];

        $payload['downloadTransferId'] = $transfer->id;
        $payload['original'] = $transfer->url;

        if ($transfer->isTerminal()) {
            $file = $transfer->file()->first();
            if ($file || $transfer->url) {
                $payload = [
                    ...$payload,
                    ...self::filePayload($file, $transfer->url),
                ];
            }
        }

        if ($transfer->status !== DownloadTransferStatus::COMPLETED) {
            $payload['downloaded_at'] = null;
        }

        return $payload;
    }

    /**
     * @param  array<int, array{
     *     extension_channel:string|null,
     *     download_via:string|null,
     *     reaction:string|null,
     *     referrer_url:string|null,
     *     downloaded_at:string|null,
     *     blacklisted_at:string|null,
     *     search_text:string
     * }>  $lookups
     * @return array<string, mixed>
     */
    private static function listPayload(DownloadTransfer $transfer, array $lookups = []): array
    {
        return [
            'id' => $transfer->id,
            'fileId' => $transfer->file_id,
            'file_id' => $transfer->file_id,
            'status' => $transfer->status,
            'created_at' => $transfer->created_at?->toISOString(),
            'queued_at' => $transfer->queued_at?->toISOString(),
            'started_at' => $transfer->started_at?->toISOString(),
            'finished_at' => $transfer->finished_at?->toISOString(),
            'failed_at' => $transfer->failed_at?->toISOString(),
            'percent' => (int) ($transfer->last_broadcast_percent ?? 0),
            'error' => $transfer->error,
            ...DownloadTransferActionAvailability::forPayload($transfer),
            ...DownloadTransferListFacts::forTransfer($transfer, $lookups),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function filePayload(?File $file, ?string $sourceUrl): array
    {
        $isDownloadedFile = (bool) ($file?->downloaded && $file?->path);

        $original = $isDownloadedFile
            ? FileApiPath::downloaded($file->id)
            : $sourceUrl;
        if (! $original && $file?->url) {
            $original = $file->url;
        }
        if (! $original && $file?->path) {
            $original = FileApiPath::serve($file->id);
        }

        $preview = $isDownloadedFile && $file?->preview_path
            ? FileApiPath::preview($file->id)
            : ($file?->preview_url ?? $original);
        $plannedPath = self::plannedPath($file, $sourceUrl);
        $path = $file?->path ?? $plannedPath;
        $absolutePath = $path
            ? Storage::disk(config('downloads.disk'))->path($path)
            : null;

        return [
            'asset_url' => self::assetUrl($file, $sourceUrl),
            'path' => $path,
            'absolute_path' => $absolutePath,
            'original' => $original,
            'referrer_url' => $file?->referrer_url,
            'downloaded_at' => $file?->downloaded_at?->toIso8601String(),
            'blacklisted_at' => $file?->blacklisted_at?->toIso8601String(),
            'preview' => $preview,
            'size' => $file?->size,
            'filename' => $file?->filename,
        ];
    }

    /**
     * @return array{id:int, atlas_url:string}|null
     */
    private static function atlasFilePayload(?File $file): ?array
    {
        if (! $file?->id) {
            return null;
        }

        return [
            'id' => (int) $file->id,
            'atlas_url' => url("/browse/file/{$file->id}"),
        ];
    }

    private static function assetUrl(?File $file, ?string $sourceUrl): ?string
    {
        if (is_string($file?->preview_url) && $file->preview_url !== '') {
            return $file->preview_url;
        }

        if (is_string($file?->url) && $file->url !== '') {
            return $file->url;
        }

        return $sourceUrl;
    }

    private static function assetUrlFileForTransfer(DownloadTransfer $transfer): ?File
    {
        if ($transfer->relationLoaded('file')) {
            return $transfer->file;
        }

        return $transfer->file()
            ->select(['id', 'preview_url', 'url'])
            ->first();
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
