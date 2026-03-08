<?php

namespace App\Services\Downloads;

use App\Models\DownloadTransfer;
use App\Models\File;
use App\Models\Reaction;
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
        $lookups = self::buildListLookups($transfers);

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
            'percent' => $percent,
            'error' => $transfer->error,
            ...self::transferFacts($transfer),
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

        return $payload;
    }

    /**
     * @param  array<int, array{
     *     extension_channel:string|null,
     *     reaction:string|null,
     *     referrer_url:string|null,
     *     downloaded_at:string|null,
     *     blacklisted_at:string|null
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
            ...self::transferFacts($transfer, $lookups),
        ];
    }

    /**
     * @param  array<int, array{
     *     extension_channel:string|null,
     *     reaction:string|null,
     *     referrer_url:string|null,
     *     downloaded_at:string|null,
     *     blacklisted_at:string|null
     * }>  $lookups
     * @return array{
     *     extension_channel:string|null,
     *     reaction:string|null,
     *     referrer_url:string|null,
     *     downloaded_at:string|null,
     *     blacklisted_at:string|null
     * }
     */
    private static function transferFacts(DownloadTransfer $transfer, array $lookups = []): array
    {
        $lookupKey = self::transferLookupKey($transfer);
        if (array_key_exists($lookupKey, $lookups)) {
            return $lookups[$lookupKey];
        }

        $file = self::listingMetadataFileForTransfer($transfer);
        $metadata = is_array($file?->listing_metadata) ? $file->listing_metadata : [];
        $reaction = null;
        $extensionUserId = self::extensionUserIdFromListingMetadata($metadata);

        if ($transfer->file_id !== null && $extensionUserId !== null) {
            $resolved = Reaction::query()
                ->where('file_id', $transfer->file_id)
                ->where('user_id', $extensionUserId)
                ->value('type');

            $reaction = is_string($resolved) ? $resolved : null;
        }

        return [
            'extension_channel' => self::extensionChannelFromListingMetadata($metadata),
            'reaction' => $reaction,
            'referrer_url' => self::trimmedStringOrNull($file?->referrer_url),
            'downloaded_at' => self::datetimeToIsoStringOrNull($file?->downloaded_at),
            'blacklisted_at' => self::datetimeToIsoStringOrNull($file?->blacklisted_at),
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

    /**
     * @param  Collection<int, DownloadTransfer>  $transfers
     * @return array<int, array{
     *     extension_channel:string|null,
     *     reaction:string|null,
     *     referrer_url:string|null,
     *     downloaded_at:string|null,
     *     blacklisted_at:string|null
     * }>
     */
    private static function buildListLookups(Collection $transfers): array
    {
        if ($transfers->isEmpty()) {
            return [];
        }

        self::preloadListFiles($transfers);

        /** @var array<string, array{file_id:int,user_id:int}> $pairs */
        $pairs = [];
        /** @var array<int, string> $pairByTransferLookupKey */
        $pairByTransferLookupKey = [];
        /** @var array<int, array{
         *     extension_channel:string|null,
         *     reaction:string|null,
         *     referrer_url:string|null,
         *     downloaded_at:string|null,
         *     blacklisted_at:string|null
         * }> $lookups
         */
        $lookups = [];

        foreach ($transfers as $transfer) {
            $lookupKey = self::transferLookupKey($transfer);
            $file = self::listingMetadataFileForTransfer($transfer);
            $listing = is_array($file?->listing_metadata) ? $file->listing_metadata : [];

            $lookups[$lookupKey] = [
                'extension_channel' => self::extensionChannelFromListingMetadata($listing),
                'reaction' => null,
                'referrer_url' => self::trimmedStringOrNull($file?->referrer_url),
                'downloaded_at' => self::datetimeToIsoStringOrNull($file?->downloaded_at),
                'blacklisted_at' => self::datetimeToIsoStringOrNull($file?->blacklisted_at),
            ];

            if ($transfer->file_id === null) {
                continue;
            }

            $extensionUserId = self::extensionUserIdFromListingMetadata($listing);

            if ($extensionUserId === null) {
                continue;
            }

            $pairKey = self::reactionPairKey((int) $transfer->file_id, $extensionUserId);
            $pairs[$pairKey] = [
                'file_id' => (int) $transfer->file_id,
                'user_id' => $extensionUserId,
            ];
            $pairByTransferLookupKey[$lookupKey] = $pairKey;
        }

        if ($pairs === []) {
            return $lookups;
        }

        $fileIds = array_values(array_unique(array_column($pairs, 'file_id')));
        $userIds = array_values(array_unique(array_column($pairs, 'user_id')));

        $reactionByPair = Reaction::query()
            ->select(['file_id', 'user_id', 'type'])
            ->whereIn('file_id', $fileIds)
            ->whereIn('user_id', $userIds)
            ->get()
            ->reduce(
                /**
                 * @param  array<string, string>  $carry
                 */
                static function (array $carry, Reaction $reaction) use ($pairs): array {
                    $pairKey = self::reactionPairKey((int) $reaction->file_id, (int) $reaction->user_id);
                    if (! array_key_exists($pairKey, $pairs) || ! is_string($reaction->type)) {
                        return $carry;
                    }

                    $carry[$pairKey] = $reaction->type;

                    return $carry;
                },
                [],
            );

        foreach ($pairByTransferLookupKey as $lookupKey => $pairKey) {
            $lookups[$lookupKey]['reaction'] = $reactionByPair[$pairKey] ?? null;
        }

        return $lookups;
    }

    /**
     * @param  Collection<int, DownloadTransfer>  $transfers
     */
    private static function preloadListFiles(Collection $transfers): void
    {
        $fileIds = $transfers
            ->filter(static fn (DownloadTransfer $transfer): bool => $transfer->file_id !== null && ! $transfer->relationLoaded('file'))
            ->pluck('file_id')
            ->filter(static fn (mixed $fileId): bool => is_numeric($fileId))
            ->map(static fn (mixed $fileId): int => (int) $fileId)
            ->unique()
            ->values();

        if ($fileIds->isEmpty()) {
            return;
        }

        $filesById = File::query()
            ->select(['id', 'listing_metadata', 'referrer_url', 'downloaded_at', 'blacklisted_at'])
            ->whereIn('id', $fileIds->all())
            ->get()
            ->keyBy('id');

        foreach ($transfers as $transfer) {
            if ($transfer->file_id === null || $transfer->relationLoaded('file')) {
                continue;
            }

            $transfer->setRelation('file', $filesById->get((int) $transfer->file_id));
        }
    }

    private static function reactionPairKey(int $fileId, int $userId): string
    {
        return "{$fileId}:{$userId}";
    }

    private static function transferLookupKey(DownloadTransfer $transfer): int
    {
        return spl_object_id($transfer);
    }

    private static function listingMetadataFileForTransfer(DownloadTransfer $transfer): ?File
    {
        $file = $transfer->relationLoaded('file') ? $transfer->file : null;
        if ($file instanceof File && ! self::hasListingMetadataAttribute($file)) {
            $file = null;
        }

        if ($file) {
            return $file;
        }

        return $transfer->file()
            ->select(['id', 'listing_metadata', 'referrer_url', 'downloaded_at', 'blacklisted_at'])
            ->first();
    }

    private static function hasListingMetadataAttribute(File $file): bool
    {
        return array_key_exists('listing_metadata', $file->getAttributes());
    }

    private static function trimmedStringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private static function datetimeToIsoStringOrNull(mixed $value): ?string
    {
        if (! $value instanceof \DateTimeInterface) {
            return null;
        }

        return $value->format(DATE_ATOM);
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
