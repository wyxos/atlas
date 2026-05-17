<?php

namespace App\Services\Downloads;

use App\Models\DownloadTransfer;
use App\Models\File;
use App\Models\Reaction;
use Illuminate\Support\Collection;

final class DownloadTransferListFacts
{
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
     * @return array{
     *     extension_channel:string|null,
     *     download_via:string|null,
     *     reaction:string|null,
     *     referrer_url:string|null,
     *     downloaded_at:string|null,
     *     blacklisted_at:string|null,
     *     search_text:string
     * }
     */
    public static function forTransfer(DownloadTransfer $transfer, array $lookups = []): array
    {
        $lookupKey = self::transferLookupKey($transfer);
        if (array_key_exists($lookupKey, $lookups)) {
            return $lookups[$lookupKey];
        }

        $file = self::listingMetadataFileForTransfer($transfer);
        $metadata = is_array($file?->listing_metadata) ? $file->listing_metadata : [];
        $reaction = null;
        $extensionUserId = self::extensionUserIdFromListingMetadata($metadata);
        $extensionChannel = self::extensionChannelFromListingMetadata($metadata);
        $downloadVia = self::downloadViaFromListingMetadata($metadata);
        $referrerUrl = self::trimmedStringOrNull($file?->referrer_url);

        if ($transfer->file_id !== null && $extensionUserId !== null) {
            $resolved = Reaction::query()
                ->where('file_id', $transfer->file_id)
                ->where('user_id', $extensionUserId)
                ->value('type');

            $reaction = is_string($resolved) ? $resolved : null;
        }

        return [
            'extension_channel' => $extensionChannel,
            'download_via' => $downloadVia,
            'reaction' => $reaction,
            'referrer_url' => $referrerUrl,
            'downloaded_at' => self::datetimeToIsoStringOrNull($file?->downloaded_at),
            'blacklisted_at' => self::datetimeToIsoStringOrNull($file?->blacklisted_at),
            'search_text' => self::searchTextForTransfer($transfer, $file, $metadata, $downloadVia, $reaction, $referrerUrl),
        ];
    }

    /**
     * @param  Collection<int, DownloadTransfer>  $transfers
     * @return array<int, array{
     *     extension_channel:string|null,
     *     download_via:string|null,
     *     reaction:string|null,
     *     referrer_url:string|null,
     *     downloaded_at:string|null,
     *     blacklisted_at:string|null,
     *     search_text:string
     * }>
     */
    public static function buildListLookups(Collection $transfers): array
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
         *     download_via:string|null,
         *     reaction:string|null,
         *     referrer_url:string|null,
         *     downloaded_at:string|null,
         *     blacklisted_at:string|null,
         *     search_text:string
         * }> $lookups
         */
        $lookups = [];
        /** @var array<int, DownloadTransfer> $transfersByLookupKey */
        $transfersByLookupKey = [];

        foreach ($transfers as $transfer) {
            $lookupKey = self::transferLookupKey($transfer);
            $transfersByLookupKey[$lookupKey] = $transfer;
            $file = self::listingMetadataFileForTransfer($transfer);
            $listing = is_array($file?->listing_metadata) ? $file->listing_metadata : [];
            $downloadVia = self::downloadViaFromListingMetadata($listing);
            $referrerUrl = self::trimmedStringOrNull($file?->referrer_url);

            $lookups[$lookupKey] = [
                'extension_channel' => self::extensionChannelFromListingMetadata($listing),
                'download_via' => $downloadVia,
                'reaction' => null,
                'referrer_url' => $referrerUrl,
                'downloaded_at' => self::datetimeToIsoStringOrNull($file?->downloaded_at),
                'blacklisted_at' => self::datetimeToIsoStringOrNull($file?->blacklisted_at),
                'search_text' => self::searchTextForTransfer($transfer, $file, $listing, $downloadVia, null, $referrerUrl),
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
            $transfer = $transfersByLookupKey[$lookupKey];
            $file = self::listingMetadataFileForTransfer($transfer);
            $listing = is_array($file?->listing_metadata) ? $file->listing_metadata : [];
            $lookups[$lookupKey]['search_text'] = self::searchTextForTransfer(
                $transfer,
                $file,
                $listing,
                $lookups[$lookupKey]['download_via'],
                $lookups[$lookupKey]['reaction'],
                $lookups[$lookupKey]['referrer_url'],
            );
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
            ->select(['id', 'listing_metadata', 'filename', 'path', 'url', 'referrer_url', 'downloaded_at', 'blacklisted_at'])
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
            ->select(['id', 'listing_metadata', 'filename', 'path', 'url', 'referrer_url', 'downloaded_at', 'blacklisted_at'])
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

    /**
     * @param  array<string, mixed>  $listing
     */
    private static function downloadViaFromListingMetadata(array $listing): ?string
    {
        $value = $listing['download_via'] ?? null;
        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private static function searchTextForTransfer(
        DownloadTransfer $transfer,
        ?File $file,
        array $metadata,
        ?string $downloadVia,
        ?string $reaction,
        ?string $referrerUrl,
    ): string {
        $parts = [
            $transfer->id,
            $transfer->file_id,
            $transfer->status,
            $transfer->url,
            $transfer->error,
            $downloadVia,
            $reaction,
            $referrerUrl,
            $file?->filename,
            $file?->path,
            $file?->url,
        ];

        foreach (['page_url', 'postId', 'username', 'title', 'name'] as $key) {
            $value = $metadata[$key] ?? null;
            if (is_scalar($value)) {
                $parts[] = $value;
            }
        }

        return implode(' ', array_values(array_unique(array_filter(
            array_map(static fn (mixed $part): string => trim((string) $part), $parts),
            static fn (string $part): bool => $part !== '',
        ))));
    }
}
