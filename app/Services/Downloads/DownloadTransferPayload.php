<?php

namespace App\Services\Downloads;

use App\Models\DownloadTransfer;
use App\Models\File;
use App\Models\Reaction;
use Illuminate\Support\Facades\Storage;

final class DownloadTransferPayload
{
    /**
     * @var array<string, string|null>
     */
    private static array $extensionChannelCacheByTransferId = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private static array $listingMetadataCacheByTransferId = [];

    /**
     * @var array<string, string|null>
     */
    private static array $extensionReactionCacheByTransferId = [];

    /**
     * @return array<string, mixed>
     */
    public static function forList(DownloadTransfer $transfer): array
    {
        $payload = [
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
            'extension_channel' => self::extensionChannelForTransfer($transfer),
        ];

        if ($payload['extension_channel'] !== null) {
            $payload['reaction'] = self::extensionReactionForTransfer($transfer);
        }

        return $payload;
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
            'fileId' => $transfer->file_id,
            'file_id' => $transfer->file_id,
            'status' => $transfer->status,
            'percent' => $percent,
            'original' => $transfer->url,
            'created_at' => $transfer->created_at?->toISOString(),
            'queued_at' => $transfer->queued_at?->toISOString(),
            'started_at' => $transfer->started_at?->toISOString(),
            'finished_at' => $transfer->finished_at?->toISOString(),
            'failed_at' => $transfer->failed_at?->toISOString(),
            'error' => $transfer->error,
            'extension_channel' => self::extensionChannelForTransfer($transfer),
        ];

        if ($payload['extension_channel'] !== null) {
            $payload['reaction'] = self::extensionReactionForTransfer($transfer);
        }

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

        $preview = $file?->preview_url ?? $original;
        $plannedPath = self::plannedPath($file, $sourceUrl);
        $path = $file?->path ?? $plannedPath;
        $absolutePath = $path
            ? Storage::disk(config('downloads.disk'))->path($path)
            : null;

        return [
            'path' => $path,
            'absolute_path' => $absolutePath,
            'original' => $original,
            'referrer_url' => $file?->referrer_url,
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

    private static function extensionChannelForTransfer(DownloadTransfer $transfer): ?string
    {
        $cacheKey = self::cacheKeyForTransfer($transfer);
        if (array_key_exists($cacheKey, self::$extensionChannelCacheByTransferId)) {
            return self::$extensionChannelCacheByTransferId[$cacheKey];
        }

        $channel = self::extensionChannelFromListingMetadata(
            self::listingMetadataForTransfer($transfer)
        );
        self::$extensionChannelCacheByTransferId[$cacheKey] = $channel;

        return $channel;
    }

    private static function extensionReactionForTransfer(DownloadTransfer $transfer): ?string
    {
        $cacheKey = self::cacheKeyForTransfer($transfer);
        if (array_key_exists($cacheKey, self::$extensionReactionCacheByTransferId)) {
            return self::$extensionReactionCacheByTransferId[$cacheKey];
        }

        if ($transfer->file_id === null) {
            self::$extensionReactionCacheByTransferId[$cacheKey] = null;

            return null;
        }

        $extensionUserId = self::extensionUserIdFromListingMetadata(
            self::listingMetadataForTransfer($transfer)
        );
        if ($extensionUserId === null) {
            self::$extensionReactionCacheByTransferId[$cacheKey] = null;

            return null;
        }

        $reaction = Reaction::query()
            ->where('file_id', $transfer->file_id)
            ->where('user_id', $extensionUserId)
            ->value('type');

        $resolved = is_string($reaction) ? $reaction : null;
        self::$extensionReactionCacheByTransferId[$cacheKey] = $resolved;

        return $resolved;
    }

    /**
     * @return array<string, mixed>
     */
    private static function listingMetadataForTransfer(DownloadTransfer $transfer): array
    {
        $cacheKey = self::cacheKeyForTransfer($transfer);
        if (array_key_exists($cacheKey, self::$listingMetadataCacheByTransferId)) {
            return self::$listingMetadataCacheByTransferId[$cacheKey];
        }

        $file = $transfer->relationLoaded('file') ? $transfer->file : null;
        if ($file instanceof File && ! self::hasListingMetadataAttribute($file)) {
            $file = null;
        }

        if (! $file) {
            $file = $transfer->file()->select(['id', 'listing_metadata'])->first();
        }

        $metadata = is_array($file?->listing_metadata) ? $file->listing_metadata : [];
        self::$listingMetadataCacheByTransferId[$cacheKey] = $metadata;

        return $metadata;
    }

    private static function cacheKeyForTransfer(DownloadTransfer $transfer): string
    {
        $createdAt = $transfer->created_at?->format('Y-m-d H:i:s.u') ?? 'na';
        $urlHash = hash('sha256', (string) ($transfer->url ?? ''));

        return "{$transfer->id}:{$transfer->file_id}:{$createdAt}:{$urlHash}";
    }

    private static function hasListingMetadataAttribute(File $file): bool
    {
        return array_key_exists('listing_metadata', $file->getAttributes());
    }

    /**
     * @param  array<string, mixed>  $listing
     */
    private static function extensionChannelFromListingMetadata(array $listing): ?string
    {
        $value = $listing['extension_channel'] ?? null;
        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));

        return preg_match('/^[a-f0-9]{64}$/', $normalized) === 1 ? $normalized : null;
    }

    /**
     * @param  array<string, mixed>  $listing
     */
    private static function extensionUserIdFromListingMetadata(array $listing): ?int
    {
        $value = $listing['extension_user_id'] ?? null;
        if (! is_numeric($value)) {
            return null;
        }

        $normalized = (int) $value;

        return $normalized > 0 ? $normalized : null;
    }
}
