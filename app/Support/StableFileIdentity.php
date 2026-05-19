<?php

namespace App\Support;

use App\Models\File;
use App\Services\CivitAiImages;
use App\Services\DeviantArtImages;

class StableFileIdentity
{
    /**
     * @param  array<int, array{file: array<string, mixed>, metadata: array<string, mixed>, url: string}>  $preparedItems
     * @return array<int, array{file: array<string, mixed>, metadata: array<string, mixed>, url: string}>
     */
    public static function mergeExistingUrls(array $preparedItems): array
    {
        $existingFiles = self::existingFilesByStableIdentity($preparedItems);
        if ($existingFiles === []) {
            return $preparedItems;
        }

        return collect($preparedItems)
            ->map(function (array $item) use ($existingFiles): array {
                $file = $item['file'];
                $existingFile = self::matchingStableIdentityFile($file, $existingFiles);
                if (! $existingFile) {
                    return $item;
                }

                $existingUrl = trim((string) ($existingFile->url ?? ''));
                if ($existingUrl === '') {
                    return $item;
                }

                $file['url'] = $existingUrl;
                $file['url_hash'] = hash('sha256', $existingUrl);
                $item['file'] = $file;
                $item['url'] = $existingUrl;

                return $item;
            })
            ->values()
            ->all();
    }

    public static function findExistingFile(string $source, ?string $sourceId, ?string $referrerUrl): ?File
    {
        $referrerHash = self::hashUrl($referrerUrl);
        if ($referrerHash !== '' && self::supportsStableReferrerIdentity($source)) {
            $query = File::query()
                ->where('source', $source)
                ->where('referrer_url_hash', $referrerHash);

            if ($source === DeviantArtImages::SOURCE) {
                $query->whereNotNull('source_id')
                    ->whereRaw("TRIM(source_id) <> ''");
            }

            $file = $query->orderByDesc('downloaded')
                ->latest('updated_at')
                ->first();
            if ($file) {
                return $file;
            }
        }

        $sourceId = is_string($sourceId) ? trim($sourceId) : '';
        if ($sourceId === '' || ! self::supportsStableSourceIdentity($source)) {
            return null;
        }

        return File::query()
            ->where('source', $source)
            ->where('source_id', $sourceId)
            ->orderByDesc('downloaded')
            ->latest('updated_at')
            ->first();
    }

    /**
     * @param  array<int, array{file: array<string, mixed>, metadata: array<string, mixed>, url: string}>  $preparedItems
     * @return array<string, File>
     */
    private static function existingFilesByStableIdentity(array $preparedItems): array
    {
        $sourceIdsBySource = [];
        $deviantArtReferrerHashes = [];

        foreach ($preparedItems as $item) {
            $file = $item['file'];
            $source = trim((string) ($file['source'] ?? ''));
            $sourceId = trim((string) ($file['source_id'] ?? ''));
            $referrerHash = self::referrerHashFromFile($file);

            if ($sourceId !== '' && self::supportsStableSourceIdentity($source)) {
                $sourceIdsBySource[$source][] = $sourceId;
            }

            if ($source === DeviantArtImages::SOURCE && $referrerHash !== '') {
                $deviantArtReferrerHashes[] = $referrerHash;
            }
        }

        if ($sourceIdsBySource === [] && $deviantArtReferrerHashes === []) {
            return [];
        }

        $files = File::query()
            ->where(function ($query) use ($sourceIdsBySource, $deviantArtReferrerHashes): void {
                foreach ($sourceIdsBySource as $source => $sourceIds) {
                    $query->orWhere(function ($query) use ($source, $sourceIds): void {
                        $query->where('source', $source)
                            ->whereIn('source_id', array_values(array_unique($sourceIds)));
                    });
                }

                if ($deviantArtReferrerHashes !== []) {
                    $query->orWhere(function ($query) use ($deviantArtReferrerHashes): void {
                        $query->where('source', DeviantArtImages::SOURCE)
                            ->whereIn('referrer_url_hash', array_values(array_unique($deviantArtReferrerHashes)));
                    });
                }
            })
            ->orderByDesc('downloaded')
            ->latest('updated_at')
            ->get();

        $matches = [];
        foreach ($files as $file) {
            foreach (self::stableIdentityKeys($file->getAttributes()) as $key) {
                $matches[$key] ??= $file;
            }
        }

        return $matches;
    }

    /**
     * @param  array<string, mixed>  $file
     * @param  array<string, File>  $existingFiles
     */
    private static function matchingStableIdentityFile(array $file, array $existingFiles): ?File
    {
        foreach (self::stableIdentityKeys($file) as $key) {
            if (isset($existingFiles[$key])) {
                return $existingFiles[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $file
     * @return list<string>
     */
    private static function stableIdentityKeys(array $file): array
    {
        $source = trim((string) ($file['source'] ?? ''));
        $sourceId = trim((string) ($file['source_id'] ?? ''));
        $referrerHash = self::referrerHashFromFile($file);
        $keys = [];

        if ($sourceId !== '' && self::supportsStableSourceIdentity($source)) {
            $keys[] = 'source_id:'.$source.':'.$sourceId;
        }

        if ($source === DeviantArtImages::SOURCE && $referrerHash !== '') {
            $keys[] = 'referrer:'.$source.':'.$referrerHash;
        }

        return $keys;
    }

    private static function supportsStableSourceIdentity(string $source): bool
    {
        return in_array($source, [CivitAiImages::SOURCE, DeviantArtImages::SOURCE], true);
    }

    private static function supportsStableReferrerIdentity(string $source): bool
    {
        return in_array($source, [CivitAiImages::SOURCE, DeviantArtImages::SOURCE], true);
    }

    /**
     * @param  array<string, mixed>  $file
     */
    private static function referrerHashFromFile(array $file): string
    {
        $referrerHash = trim((string) ($file['referrer_url_hash'] ?? ''));
        if ($referrerHash !== '') {
            return $referrerHash;
        }

        return self::hashUrl($file['referrer_url'] ?? null);
    }

    private static function hashUrl(mixed $url): string
    {
        $url = is_string($url) ? trim($url) : '';

        return $url !== '' ? hash('sha256', $url) : '';
    }
}
