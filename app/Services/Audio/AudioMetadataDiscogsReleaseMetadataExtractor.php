<?php

namespace App\Services\Audio;

class AudioMetadataDiscogsReleaseMetadataExtractor
{
    public function __construct(
        private readonly AudioMetadataValueExtractor $values,
    ) {}

    /**
     * @param  list<array{release:array<string, mixed>,track:array<string, mixed>|null,confidence:int,evidence:array<string, mixed>}>  $candidates
     * @return list<array<string, mixed>>
     */
    public function adjudicationCandidates(array $candidates): array
    {
        return array_values(array_map(function (array $candidate): array {
            $release = $candidate['release'];
            $track = $candidate['track'];
            $matchedFields = $this->values->cleanStringList($candidate['evidence']['matched_existing_fields'] ?? []);

            return [
                'release_id' => $this->values->cleanString($candidate['evidence']['discogs_release_id'] ?? $release['id'] ?? null),
                'release_title' => $this->values->cleanString($release['title'] ?? null),
                'release_url' => $candidate['evidence']['discogs_release_url'] ?? null,
                'master_id' => $this->values->cleanString($release['master_id'] ?? null),
                'master_url' => $candidate['evidence']['discogs_master_url'] ?? null,
                'country' => $this->values->cleanString($release['country'] ?? null),
                'released' => $this->values->cleanString($release['released'] ?? $release['year'] ?? null),
                'data_quality' => $this->values->cleanString($release['data_quality'] ?? data_get($release, 'community.data_quality')),
                'format_quantity' => $this->values->cleanString($release['format_quantity'] ?? null),
                'formats' => $this->releaseFormats($release),
                'labels' => $this->releaseLabels($release),
                'barcodes' => $this->releaseBarcodes($release),
                'matched_existing_fields' => $matchedFields,
                'confidence' => $candidate['confidence'],
                'duration_delta_seconds' => $candidate['evidence']['duration_delta_seconds'] ?? null,
                'matched_track' => $track !== null ? [
                    'position' => $this->values->cleanString($track['position'] ?? null),
                    'title' => $this->values->cleanString($track['title'] ?? null),
                    'duration' => $this->values->cleanString($track['duration'] ?? null),
                    'track_number' => $this->trackNumber($track),
                    'disc_number' => $this->discNumber($track),
                ] : null,
                'cover_source' => $candidate['evidence']['cover_source'] ?? null,
                'values' => $this->valuesFromRelease($release, $track, $matchedFields),
            ];
        }, $candidates));
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>|null  $review
     * @return array<string, mixed>
     */
    public function valuesAllowedByReleaseAdjudication(array $values, ?array $review): array
    {
        if ($review === null) {
            return $values;
        }

        $safeFields = is_array($review['safe_fields'] ?? null)
            ? $this->values->cleanStringList($review['safe_fields'])
            : [];

        return $safeFields !== []
            ? array_intersect_key($values, array_flip($safeFields))
            : $values;
    }

    /**
     * @param  array<string, mixed>  $release
     * @param  array<string, mixed>|null  $track
     * @param  list<string>  $matchedFields
     * @return array<string, mixed>
     */
    public function valuesFromRelease(array $release, ?array $track, array $matchedFields): array
    {
        $values = [];
        $releaseTitle = $this->sourceTitleCanonical($this->values->cleanString($release['title'] ?? null));

        if (in_array('album', $matchedFields, true) || in_array('artists', $matchedFields, true)) {
            $this->putIfPresent($values, 'album', $releaseTitle);
        }

        if (in_array('artists', $matchedFields, true)) {
            $this->putIfPresent($values, 'artists', $this->trackArtists($track) ?: $this->releaseArtists($release));
        }

        $this->putIfPresent($values, 'release_label', $this->firstLabelName($release));
        $this->putIfPresent($values, 'catalog_number', $this->firstCatalogNumber($release));
        $this->putIfPresent($values, 'barcode', $this->firstBarcode($release));
        $this->putIfPresent($values, 'release_date', $this->values->cleanString($release['released'] ?? $release['year'] ?? null));
        $this->putIfPresent($values, 'release_country', $this->values->cleanString($release['country'] ?? null));
        $this->putIfPresent($values, 'discogs_release_id', $this->values->cleanString($release['id'] ?? null));
        $this->putIfPresent($values, 'cover_url', $this->coverUrl($release));

        if ($track !== null) {
            $trackTitle = $this->sourceTitleCanonical($this->values->cleanString($track['title'] ?? null));
            $this->putIfPresent($values, 'title', $trackTitle);
            $this->putIfPresent($values, 'track_number', $this->trackNumber($track));
            $this->putIfPresent($values, 'disc_number', $this->discNumber($track));
            $this->putIfPresent($values, 'duration_seconds', $this->durationSeconds($track['duration'] ?? null));
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    public function releaseArtists(array $release): array
    {
        return $this->values->cleanStringList(data_get($release, 'artists.*.name', []));
    }

    public function firstCatalogNumber(array $release): ?string
    {
        return collect(data_get($release, 'labels', []))
            ->map(fn (mixed $label): ?string => is_array($label) ? $this->values->cleanString($label['catno'] ?? null) : null)
            ->filter(fn (?string $catno): bool => $catno !== null && ! in_array(mb_strtolower($catno), ['none', '[none]'], true))
            ->first();
    }

    public function firstBarcode(array $release): ?string
    {
        return collect(data_get($release, 'identifiers', []))
            ->filter(fn (mixed $identifier): bool => is_array($identifier)
                && str_contains(mb_strtolower((string) ($identifier['type'] ?? '')), 'barcode'))
            ->map(fn (array $identifier): ?string => $this->values->cleanString($identifier['value'] ?? null))
            ->filter()
            ->first();
    }

    public function coverUrl(array $release): ?string
    {
        $images = collect(data_get($release, 'images', []))
            ->filter(fn (mixed $image): bool => is_array($image));
        $image = $images->first(fn (array $image): bool => mb_strtolower((string) ($image['type'] ?? '')) === 'primary')
            ?? $images->first();

        return is_array($image)
            ? $this->values->cleanString($image['uri'] ?? $image['resource_url'] ?? $image['uri150'] ?? null)
            : null;
    }

    public function discogsReleaseUrl(array $release, ?string $releaseId): ?string
    {
        $uri = $this->values->cleanString($release['uri'] ?? null);
        if ($uri !== null) {
            if (str_starts_with($uri, 'https://www.discogs.com/')) {
                return $uri;
            }

            if (str_starts_with($uri, '/')) {
                return 'https://www.discogs.com'.$uri;
            }
        }

        return $releaseId !== null ? 'https://www.discogs.com/release/'.$releaseId : null;
    }

    public function discogsMasterUrl(array $release): ?string
    {
        $uri = $this->values->cleanString($release['discogs_master_uri'] ?? null);
        if ($uri !== null) {
            return $uri;
        }

        $masterId = $this->values->cleanString($release['master_id'] ?? null);

        return $masterId !== null ? 'https://www.discogs.com/master/'.$masterId : null;
    }

    public function trackNumber(array $track): ?string
    {
        $position = $this->values->cleanString($track['position'] ?? null);
        if ($position === null) {
            return null;
        }

        if (preg_match('/^(?:CD|Disc)?\s*(\d+)[-.](\d+)$/i', $position, $matches) === 1) {
            return $matches[2];
        }

        return $position;
    }

    public function discNumber(array $track): ?string
    {
        $position = $this->values->cleanString($track['position'] ?? null);
        if ($position === null) {
            return null;
        }

        if (preg_match('/^(?:CD|Disc)?\s*(\d+)[-.]\d+$/i', $position, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    public function durationSeconds(mixed $duration): ?int
    {
        $duration = $this->values->cleanString($duration);
        if ($duration === null || ! str_contains($duration, ':')) {
            return null;
        }

        $parts = array_map('intval', explode(':', $duration));
        if (count($parts) === 2) {
            return ($parts[0] * 60) + $parts[1];
        }

        if (count($parts) === 3) {
            return ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
        }

        return null;
    }

    private function sourceTitleCanonical(?string $title): ?string
    {
        $title = $this->values->cleanString($title);
        if ($title === null) {
            return null;
        }

        $parts = array_values(array_filter(
            array_map(fn (string $part): ?string => $this->values->cleanString($part), preg_split('/\s*=\s*/', $title) ?: []),
            fn (?string $part): bool => $part !== null
        ));

        return $parts[0] ?? $title;
    }

    /**
     * @return list<string>
     */
    private function trackArtists(?array $track): array
    {
        if ($track === null) {
            return [];
        }

        return $this->values->cleanStringList(data_get($track, 'artists.*.name', []));
    }

    /**
     * @return list<array{name:string|null,qty:string|null,descriptions:list<string>}>
     */
    private function releaseFormats(array $release): array
    {
        $formats = data_get($release, 'formats', []);
        if (! is_array($formats)) {
            return [];
        }

        return collect($formats)
            ->filter(fn (mixed $format): bool => is_array($format))
            ->map(fn (array $format): array => [
                'name' => $this->values->cleanString($format['name'] ?? null),
                'qty' => $this->values->cleanString($format['qty'] ?? null),
                'descriptions' => $this->values->cleanStringList($format['descriptions'] ?? []),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{name:string|null,catno:string|null}>
     */
    private function releaseLabels(array $release): array
    {
        $labels = data_get($release, 'labels', []);
        if (! is_array($labels)) {
            return [];
        }

        return collect($labels)
            ->filter(fn (mixed $label): bool => is_array($label))
            ->map(fn (array $label): array => [
                'name' => $this->values->cleanString($label['name'] ?? null),
                'catno' => $this->values->cleanString($label['catno'] ?? null),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function releaseBarcodes(array $release): array
    {
        return collect(data_get($release, 'identifiers', []))
            ->filter(fn (mixed $identifier): bool => is_array($identifier)
                && str_contains(mb_strtolower((string) ($identifier['type'] ?? '')), 'barcode'))
            ->map(fn (array $identifier): ?string => $this->values->cleanString($identifier['value'] ?? null))
            ->filter()
            ->values()
            ->all();
    }

    private function firstLabelName(array $release): ?string
    {
        return collect(data_get($release, 'labels', []))
            ->map(fn (mixed $label): ?string => is_array($label) ? $this->values->cleanString($label['name'] ?? null) : null)
            ->filter(fn (?string $label): bool => $label !== null && mb_strtolower($label) !== 'not on label')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function putIfPresent(array &$values, string $key, mixed $value): void
    {
        if ($value === null || $value === []) {
            return;
        }

        $values[$key] = $value;
    }
}
