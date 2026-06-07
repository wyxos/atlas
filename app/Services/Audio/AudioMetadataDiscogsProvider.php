<?php

namespace App\Services\Audio;

use App\Models\File;

class AudioMetadataDiscogsProvider
{
    public function __construct(
        private readonly AudioMetadataDiscogsClient $discogs,
        private readonly AudioMetadataDiscogsSearchQueryExpander $searchQueryExpander,
        private readonly AudioMetadataDiscogsTrackMatcher $trackMatcher,
        private readonly AudioMetadataValueExtractor $values,
    ) {}

    /**
     * @param  array<string, mixed>  $currentValues
     * @return array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}|null
     */
    public function candidate(File $file, array $currentValues): ?array
    {
        if (! $this->discogs->configured()) {
            return null;
        }

        $album = $this->values->cleanString($currentValues['album'] ?? null);
        $artists = $this->values->cleanStringList($currentValues['artists'] ?? []);
        $title = $this->values->cleanString($currentValues['title'] ?? $file->title);
        $duration = $this->values->positiveInteger($currentValues['duration_seconds'] ?? null);

        if ($album === null || $artists === []) {
            return null;
        }

        $match = $this->bestReleaseMatch($album, $artists, $title, $duration);
        if ($match === null) {
            return null;
        }

        $values = $this->valuesFromRelease(
            $match['release'],
            $match['track'],
            $match['evidence']['matched_existing_fields'] ?? [],
        );
        if ($values === []) {
            return null;
        }

        return [
            'provider' => 'discogs_release',
            'confidence' => $match['confidence'],
            'values' => $values,
            'evidence' => $match['evidence'],
        ];
    }

    /**
     * @param  list<string>  $artists
     * @return array{release:array<string, mixed>,track:array<string, mixed>|null,confidence:int,evidence:array<string, mixed>}|null
     */
    private function bestReleaseMatch(string $album, array $artists, ?string $title, ?int $duration): ?array
    {
        $best = null;
        $seenReleaseIds = [];

        foreach ($this->releaseSearchQueries($album, $artists, $title) as $searchQuery) {
            foreach ($this->discogs->searchReleaseIds($searchQuery['release_title'], $searchQuery['artist']) as $releaseId) {
                if (isset($seenReleaseIds[$releaseId])) {
                    continue;
                }

                $seenReleaseIds[$releaseId] = true;
                $release = $this->discogs->fetchRelease($releaseId);
                if ($release === []) {
                    continue;
                }

                $score = $this->scoreRelease($release, $album, $artists, $title, $duration);
                if ($score['confidence'] < 72) {
                    continue;
                }

                $candidate = [
                    'release' => $release,
                    'track' => $score['track'],
                    'confidence' => $score['confidence'],
                    'evidence' => $score['evidence'],
                ];

                if ($best === null || $this->candidateBeats($candidate, $best)) {
                    $best = $candidate;
                }
            }
        }

        return $best;
    }

    private function candidateBeats(array $candidate, array $best): bool
    {
        $candidatePriority = $this->releaseMatchPriority($candidate['evidence']);
        $bestPriority = $this->releaseMatchPriority($best['evidence']);

        if ($candidatePriority !== $bestPriority) {
            return $candidatePriority > $bestPriority;
        }

        return $candidate['confidence'] > $best['confidence'];
    }

    private function releaseMatchPriority(array $evidence): int
    {
        $matchedFields = $this->values->cleanStringList($evidence['matched_existing_fields'] ?? []);
        $durationDelta = $evidence['duration_delta_seconds'] ?? null;
        $hasExactDuration = is_numeric($durationDelta) && (int) $durationDelta <= 2;
        $hasFields = fn (array $fields): bool => count(array_intersect($fields, $matchedFields)) === count($fields);

        return match (true) {
            $hasExactDuration && $hasFields(['album', 'artists', 'track', 'duration']) => 30,
            $hasExactDuration && $hasFields(['release_title', 'artists', 'track', 'duration']) => 20,
            $hasFields(['album', 'artists']) => 10,
            default => 0,
        };
    }

    /**
     * @param  array<string, mixed>  $release
     * @return array{confidence:int,track:array<string, mixed>|null,evidence:array<string, mixed>}
     */
    private function scoreRelease(array $release, string $album, array $artists, ?string $title, ?int $duration): array
    {
        $confidence = 62;
        $matchedFields = [];
        $releaseTitle = $this->values->cleanString($release['title'] ?? null);
        $releaseArtists = $this->releaseArtists($release);
        $track = $this->trackMatcher->bestTrack($release, $title, $duration);
        $trackDuration = $this->discogsDurationSeconds($track['duration'] ?? null);
        $durationDelta = $duration !== null && $trackDuration !== null ? abs($duration - $trackDuration) : null;
        $coverUrl = $this->coverUrl($release);

        if ($this->titlesMatch($album, $releaseTitle)) {
            $confidence += 10;
            $matchedFields[] = 'album';
        } else {
            $confidence -= 10;
        }

        if (! in_array('album', $matchedFields, true) && $title !== null && $this->titlesMatch($title, $releaseTitle)) {
            $confidence += 14;
            $matchedFields[] = 'release_title';
        }

        if ($this->artistsOverlap($artists, $releaseArtists)) {
            $confidence += 8;
            $matchedFields[] = 'artists';
        } else {
            $confidence -= 10;
        }

        if ($track !== null) {
            $confidence += 9;
            $matchedFields[] = 'track';
        }

        if ($durationDelta !== null) {
            if ($durationDelta <= 2) {
                $confidence += 4;
                $matchedFields[] = 'duration';
            } elseif ($durationDelta <= 5) {
                $confidence += 2;
            } elseif ($durationDelta > 12) {
                $confidence -= 18;
            }
        }

        if ($this->firstBarcode($release) !== null) {
            $confidence += 2;
        }

        if ($this->firstCatalogNumber($release) !== null) {
            $confidence += 2;
        }

        if ($coverUrl !== null) {
            $confidence += 2;
        }

        $releaseId = $this->values->cleanString($release['id'] ?? null);

        return [
            'confidence' => max(55, min($this->confidenceCeiling($matchedFields), $confidence)),
            'track' => $track,
            'evidence' => [
                'source' => 'discogs_release_search',
                'discogs_release_id' => $releaseId,
                'discogs_release_title' => $releaseTitle,
                'discogs_release_url' => $this->discogsReleaseUrl($release, $releaseId),
                'discogs_master_id' => $this->values->cleanString($release['master_id'] ?? null),
                'discogs_master_url' => $this->discogsMasterUrl($release),
                'matched_existing_fields' => $matchedFields,
                'duration_delta_seconds' => $durationDelta,
                'track_position' => $this->values->cleanString($track['position'] ?? null),
                'cover_source' => $coverUrl !== null ? 'discogs_images' : null,
            ],
        ];
    }

    /**
     * @param  list<string>  $matchedFields
     */
    private function confidenceCeiling(array $matchedFields): int
    {
        return in_array('release_title', $matchedFields, true) ? 94 : 90;
    }

    /**
     * @param  array<string, mixed>  $release
     * @param  array<string, mixed>|null  $track
     * @param  list<string>  $matchedFields
     * @return array<string, mixed>
     */
    private function valuesFromRelease(array $release, ?array $track, array $matchedFields): array
    {
        $values = [];
        $releaseTitle = $this->values->cleanString($release['title'] ?? null);
        $releaseTitle = $this->sourceTitleCanonical($releaseTitle);

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
            $this->putIfPresent($values, 'duration_seconds', $this->discogsDurationSeconds($track['duration'] ?? null));
        }

        return $values;
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

    private function titlesMatch(string $left, ?string $right): bool
    {
        if ($right === null) {
            return false;
        }

        $left = $this->normalizeTitle($left);
        $right = $this->normalizeTitle($right);

        return $left !== ''
            && $right !== ''
            && ($left === $right || str_contains($left, $right) || str_contains($right, $left) || $this->titleTokensMatch($left, $right));
    }

    /**
     * @param  list<string>  $artists
     * @return list<array{release_title:string,artist:string,reason:string|null}>
     */
    private function releaseSearchQueries(string $album, array $artists, ?string $title): array
    {
        $queries = [];

        foreach ($this->releaseSearchTitles($album, $title) as $releaseTitle) {
            foreach ($artists as $artist) {
                $queries[] = [
                    'release_title' => $releaseTitle,
                    'artist' => $artist,
                    'reason' => null,
                ];
            }
        }

        return $this->searchQueryExpander->expand($queries);
    }

    private function releaseSearchTitles(string $album, ?string $title): array
    {
        $clean = $this->values->cleanString($album);
        if ($clean === null) {
            return [];
        }

        $withoutSceneSuffix = preg_replace('/[-_\s]*\([A-Z0-9-]{2,}\)[-_\s]*(WEB|CD|VINYL|FLAC|MP3)?$/i', '', $clean) ?? $clean;
        $withoutFormatSuffix = preg_replace('/[-_\s]+(WEB|CD|VINYL|FLAC|MP3)$/i', '', $withoutSceneSuffix) ?? $withoutSceneSuffix;
        $spaced = str_replace(['__', '_'], ' ', $withoutFormatSuffix);
        $withoutEpSuffix = preg_replace('/\s+EP$/i', '', $spaced) ?? $spaced;

        return collect([$title, $clean, $withoutSceneSuffix, $withoutFormatSuffix, $spaced, $withoutEpSuffix])
            ->map(fn (mixed $candidate): ?string => $this->values->cleanString($candidate))
            ->filter()
            ->unique(fn (string $candidate): string => $this->normalizeTitle($candidate))
            ->values()
            ->all();
    }

    private function releaseArtists(array $release): array
    {
        return $this->values->cleanStringList(data_get($release, 'artists.*.name', []));
    }

    private function trackArtists(?array $track): array
    {
        if ($track === null) {
            return [];
        }

        return $this->values->cleanStringList(data_get($track, 'artists.*.name', []));
    }

    private function artistsOverlap(array $left, array $right): bool
    {
        $left = array_map(fn (string $name): string => $this->normalizeName($name), $left);
        $right = array_map(fn (string $name): string => $this->normalizeName($name), $right);

        return array_values(array_intersect($left, $right)) !== [];
    }

    private function firstLabelName(array $release): ?string
    {
        return collect(data_get($release, 'labels', []))
            ->map(fn (mixed $label): ?string => is_array($label) ? $this->values->cleanString($label['name'] ?? null) : null)
            ->filter(fn (?string $label): bool => $label !== null && mb_strtolower($label) !== 'not on label')
            ->first();
    }

    private function firstCatalogNumber(array $release): ?string
    {
        return collect(data_get($release, 'labels', []))
            ->map(fn (mixed $label): ?string => is_array($label) ? $this->values->cleanString($label['catno'] ?? null) : null)
            ->filter(fn (?string $catno): bool => $catno !== null && ! in_array(mb_strtolower($catno), ['none', '[none]'], true))
            ->first();
    }

    private function firstBarcode(array $release): ?string
    {
        return collect(data_get($release, 'identifiers', []))
            ->filter(fn (mixed $identifier): bool => is_array($identifier)
                && str_contains(mb_strtolower((string) ($identifier['type'] ?? '')), 'barcode'))
            ->map(fn (array $identifier): ?string => $this->values->cleanString($identifier['value'] ?? null))
            ->filter()
            ->first();
    }

    private function coverUrl(array $release): ?string
    {
        $images = collect(data_get($release, 'images', []))
            ->filter(fn (mixed $image): bool => is_array($image));
        $image = $images->first(fn (array $image): bool => mb_strtolower((string) ($image['type'] ?? '')) === 'primary')
            ?? $images->first();

        return is_array($image)
            ? $this->values->cleanString($image['uri'] ?? $image['resource_url'] ?? $image['uri150'] ?? null)
            : null;
    }

    private function discogsReleaseUrl(array $release, ?string $releaseId): ?string
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

    private function discogsMasterUrl(array $release): ?string
    {
        $uri = $this->values->cleanString($release['discogs_master_uri'] ?? null);
        if ($uri !== null) {
            return $uri;
        }

        $masterId = $this->values->cleanString($release['master_id'] ?? null);

        return $masterId !== null ? 'https://www.discogs.com/master/'.$masterId : null;
    }

    private function trackNumber(array $track): ?string
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

    private function discNumber(array $track): ?string
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

    private function discogsDurationSeconds(mixed $duration): ?int
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

    private function normalizeTitle(string $title): string
    {
        $title = preg_replace('/^\s*\d+\s*[-_.]\s*/', '', $title) ?? $title;
        $title = preg_replace('/\([^)]*\)|\[[^]]*]/', '', $title) ?? $title;

        return trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($title)) ?? '');
    }

    private function titleTokensMatch(string $left, string $right): bool
    {
        $leftTokens = array_values(array_unique(array_filter(explode(' ', $left))));
        $rightTokens = array_values(array_unique(array_filter(explode(' ', $right))));
        $distinctive = array_diff($leftTokens, ['tv', 'animation', 'original', 'soundtrack', 'ost']);

        return $distinctive !== []
            && array_intersect($distinctive, $rightTokens) !== []
            && count(array_intersect($leftTokens, $rightTokens)) >= min(count($leftTokens), count($rightTokens));
    }

    private function normalizeName(string $name): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($name)) ?? '';
    }
}
