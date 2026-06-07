<?php

namespace App\Services\Audio;

use App\Models\File;

class AudioMetadataCandidateEnricher
{
    public function __construct(
        private readonly AudioMetadataAiReviewer $aiReviewer,
        private readonly AudioMetadataDiscogsClient $discogs,
        private readonly AudioMetadataDiscogsSearchQueryExpander $searchQueryExpander,
        private readonly AudioMetadataDiscogsSupplementReviewer $discogsSupplementReviewer,
        private readonly AudioMetadataSourceReleaseGuard $sourceReleases,
        private readonly AudioMetadataValueExtractor $values,
    ) {}

    public function supplementWithCover(array $candidate, ?array $coverCandidate): array
    {
        if ($coverCandidate === null) {
            return $candidate;
        }

        $coverUrl = $this->values->cleanString($coverCandidate['values']['cover_url'] ?? null);
        if ($coverUrl === null) {
            return $candidate;
        }

        if (array_key_exists('cover_url', $candidate['values']) && $candidate['values']['cover_url'] !== null) {
            return $candidate;
        }

        $candidate['values']['cover_url'] = $coverUrl;
        $candidate['evidence']['cover_source'] = $coverCandidate['evidence']['cover_source'] ?? null;
        $candidate['evidence']['musicbrainz_release_id'] ??= $coverCandidate['evidence']['musicbrainz_release_id'] ?? null;

        return $candidate;
    }

    public function supplementWithDiscogs(File $file, array $currentValues, array $candidate, ?array $discogsCandidate, string $provider): array
    {
        if ($discogsCandidate === null) {
            return $candidate;
        }

        [$discogsCandidate, $provider] = $this->discogsSupplementReviewer->review(
            $file,
            $currentValues,
            $candidate,
            $discogsCandidate,
            $provider,
        );

        if (($discogsCandidate['values'] ?? []) === []) {
            return $candidate;
        }

        foreach ($discogsCandidate['values'] as $key => $value) {
            if (array_key_exists($key, $candidate['values']) && $candidate['values'][$key] !== null) {
                continue;
            }

            $candidate['values'][$key] = $value;
        }

        $candidate['provider'] = $provider;
        $candidate['confidence'] = min(96, max($candidate['confidence'], $discogsCandidate['confidence']) + 2);
        $candidate['evidence']['discogs_release_id'] = $discogsCandidate['evidence']['discogs_release_id'] ?? null;
        $candidate['evidence']['discogs_release_title'] = $discogsCandidate['evidence']['discogs_release_title'] ?? null;
        $candidate['evidence']['discogs_release_url'] = $discogsCandidate['evidence']['discogs_release_url'] ?? null;
        $candidate['evidence']['discogs_source'] = $discogsCandidate['evidence']['source'] ?? null;

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     * @return array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}
     */
    public function resolveAnomaly(File $file, array $currentValues, array $candidate): array
    {
        if (! $this->canResolveAnomaly($candidate)) {
            return $candidate;
        }

        $resolved = $this->resolveAgainstDiscogsMatches(
            $file,
            $currentValues,
            $candidate,
            $this->discogsMatches($currentValues, $candidate),
        );
        if ($resolved !== null) {
            return $resolved;
        }

        $searchQueries = $this->aiReviewer->discogsSearchQueries($file, $currentValues, $candidate);
        if ($searchQueries === []) {
            return $candidate;
        }

        $resolved = $this->resolveAgainstDiscogsMatches(
            $file,
            $currentValues,
            $candidate,
            $this->discogsMatchesForSearchQueries($searchQueries),
        );

        return $resolved ?? $candidate;
    }

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     * @return array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}
     */
    public function resolveWithAiDiscogsSearch(File $file, array $currentValues, array $candidate, string $provider): array
    {
        if (! $this->discogs->configured() || ! $this->aiReviewer->enabled()) {
            return $candidate;
        }

        $searchQueries = $this->aiReviewer->discogsSearchQueries($file, $currentValues, $candidate);
        if ($searchQueries === []) {
            return $candidate;
        }

        $resolved = $this->resolveAgainstDiscogsMatches(
            $file,
            $currentValues,
            $candidate,
            $this->discogsMatchesForSearchQueries($searchQueries),
            $provider,
        );

        return $resolved ?? $candidate;
    }

    private function canResolveAnomaly(array $candidate): bool
    {
        return ($candidate['provider'] ?? null) === 'acoustid_musicbrainz'
            && in_array($candidate['evidence']['identity_support'] ?? null, ['matched_existing_identity', 'release_with_cover'], true)
            && $this->discogs->configured()
            && $this->aiReviewer->enabled();
    }

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     * @return array<string, mixed>
     */
    private function resolveAgainstDiscogsMatches(
        File $file,
        array $currentValues,
        array $candidate,
        array $matches,
        string $provider = 'acoustid_musicbrainz_ai_discogs',
    ): ?array {
        foreach ($matches as $match) {
            $release = $match['release'];
            if ($this->sourceReleases->isLaterAlternateReleaseForCurrentSoundtrack($currentValues, $candidate['values'] ?? [], $release)) {
                continue;
            }

            $source = $this->discogsReviewSource($release);
            $review = $this->aiReviewer->resolveAnomaly($file, $currentValues, $candidate, $source);
            if (($review['verdict'] ?? null) !== 'accept') {
                continue;
            }

            if (($review['source_identity_supported'] ?? false) !== true) {
                continue;
            }

            $track = $this->selectedTrack($release, $review);
            if ($track === null) {
                continue;
            }

            return $this->applyAnomalyReview(
                $candidate,
                $currentValues,
                $release,
                $track,
                $review,
                $provider,
                $match['search_query'] ?? null,
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     * @return list<array{release:array<string, mixed>,search_query:array{release_title:string,artist:string,reason:string|null}|null}>
     */
    private function discogsMatches(array $currentValues, array $candidate): array
    {
        $album = $this->values->cleanString($currentValues['album'] ?? $candidate['values']['album'] ?? null);
        if ($album === null) {
            return [];
        }

        $artists = $this->values->cleanStringList([
            ...($currentValues['artists'] ?? []),
            ...($candidate['values']['artists'] ?? []),
        ]);

        return $this->discogsMatchesForSearchQueries([[
            'release_title' => $album,
            'artist' => $artists[0] ?? '',
            'reason' => null,
        ], ...collect($artists)
            ->skip(1)
            ->map(fn (string $artist): array => [
                'release_title' => $album,
                'artist' => $artist,
                'reason' => null,
            ])
            ->all()]);
    }

    private function discogsMatchesForSearchQueries(array $searchQueries): array
    {
        $matches = [];
        $seenReleaseIds = [];

        foreach ($this->searchQueryExpander->expand($searchQueries) as $searchQuery) {
            $releaseTitle = $this->values->cleanString($searchQuery['release_title'] ?? null);
            $artist = $this->values->cleanString($searchQuery['artist'] ?? null);
            if ($releaseTitle === null || $artist === null) {
                continue;
            }

            foreach (array_slice($this->discogs->searchReleaseIds($releaseTitle, $artist), 0, 3) as $releaseId) {
                if (isset($seenReleaseIds[$releaseId])) {
                    continue;
                }

                $seenReleaseIds[$releaseId] = true;
                $release = $this->discogs->fetchRelease($releaseId);
                if ($release === []) {
                    continue;
                }

                $matches[] = [
                    'release' => $release,
                    'search_query' => $searchQuery['reason'] === null ? null : $searchQuery,
                ];

                if (count($matches) >= 5) {
                    return $matches;
                }
            }
        }

        return $matches;
    }

    private function discogsReviewSource(array $release): array
    {
        return [
            'provider' => 'discogs_release',
            'release' => [
                'id' => $this->values->cleanString($release['id'] ?? null),
                'title' => $this->values->cleanString($release['title'] ?? null),
                'artists' => $this->releaseArtists($release),
                'country' => $this->values->cleanString($release['country'] ?? null),
                'released' => $this->values->cleanString($release['released'] ?? $release['year'] ?? null),
                'labels' => $this->labels($release),
                'identifiers' => $this->identifiers($release),
                'tracklist' => $this->tracklist($release),
            ],
        ];
    }

    private function applyAnomalyReview(
        array $candidate,
        array $currentValues,
        array $release,
        array $track,
        array $review,
        string $provider = 'acoustid_musicbrainz_ai_discogs',
        ?array $searchQuery = null,
    ): array {
        $releaseId = $this->values->cleanString($release['id'] ?? null);
        $releaseTitle = $this->sourceTitleCanonical($this->values->cleanString($release['title'] ?? null));
        $trackTitle = $this->sourceTitleCanonical($this->values->cleanString($track['title'] ?? null));

        $candidate['provider'] = $provider;
        $candidate['confidence'] = min(96, max($candidate['confidence'], (int) round(((float) ($review['confidence'] ?? 0)) * 100)));
        if ($provider === 'local_ai_discogs') {
            $this->putIfPresent($candidate['values'], 'artists', $this->releaseArtists($release));
        }

        $candidate['values']['title'] = $trackTitle;
        $candidate['values']['album'] = $releaseTitle;
        $candidate['values']['track_number'] = $this->trackNumber($track);
        $candidate['values']['disc_number'] = $this->discNumber($track);
        $this->putIfPresent($candidate['values'], 'release_label', $this->firstLabelName($release));
        $this->putIfPresent($candidate['values'], 'catalog_number', $this->firstCatalogNumber($release));
        $this->putIfPresent($candidate['values'], 'barcode', $this->firstBarcode($release));
        $this->putIfPresent($candidate['values'], 'release_date', $this->values->cleanString($release['released'] ?? $release['year'] ?? null));
        $this->putIfPresent($candidate['values'], 'release_country', $this->values->cleanString($release['country'] ?? null));
        $this->putIfPresent($candidate['values'], 'discogs_release_id', $releaseId);
        $this->putIfPresent($candidate['values'], 'cover_url', $this->coverUrl($release));

        $candidate['evidence']['ai_review'] = $review;
        $candidate['evidence']['discogs_release_id'] = $releaseId;
        $candidate['evidence']['discogs_release_title'] = $this->values->cleanString($release['title'] ?? null);
        $candidate['evidence']['discogs_release_url'] = $releaseId !== null ? 'https://www.discogs.com/release/'.$releaseId : null;
        $candidate['evidence']['discogs_source'] = 'discogs_release_search';
        $candidate['evidence']['discogs_track_position'] = $this->values->cleanString($track['position'] ?? null);
        if ($searchQuery !== null) {
            $candidate['evidence']['ai_search_plan'] = [$searchQuery];
        }

        return $candidate;
    }

    private function selectedTrack(array $release, array $review): ?array
    {
        $position = $this->values->cleanString($review['selected_track_position'] ?? null);
        $title = $this->values->cleanString($review['selected_track_title'] ?? null);

        foreach (($release['tracklist'] ?? []) as $track) {
            if (! is_array($track)) {
                continue;
            }

            $trackPosition = $this->values->cleanString($track['position'] ?? null);
            $trackTitle = $this->values->cleanString($track['title'] ?? null);
            if (($position !== null && $position === $trackPosition) || ($title !== null && $title === $trackTitle)) {
                return $track;
            }
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

    private function releaseArtists(array $release): array
    {
        return $this->values->cleanStringList(data_get($release, 'artists.*.name', []));
    }

    private function labels(array $release): array
    {
        return collect(data_get($release, 'labels', []))
            ->filter(fn (mixed $label): bool => is_array($label))
            ->map(fn (array $label): array => [
                'name' => $this->values->cleanString($label['name'] ?? null),
                'catno' => $this->values->cleanString($label['catno'] ?? null),
            ])
            ->values()
            ->all();
    }

    private function identifiers(array $release): array
    {
        return collect(data_get($release, 'identifiers', []))
            ->filter(fn (mixed $identifier): bool => is_array($identifier))
            ->map(fn (array $identifier): array => [
                'type' => $this->values->cleanString($identifier['type'] ?? null),
                'value' => $this->values->cleanString($identifier['value'] ?? null),
            ])
            ->values()
            ->all();
    }

    private function tracklist(array $release): array
    {
        return collect(data_get($release, 'tracklist', []))
            ->filter(fn (mixed $track): bool => is_array($track))
            ->map(fn (array $track): array => [
                'position' => $this->values->cleanString($track['position'] ?? null),
                'title' => $this->values->cleanString($track['title'] ?? null),
                'duration' => $this->values->cleanString($track['duration'] ?? null),
                'artists' => $this->values->cleanStringList(data_get($track, 'artists.*.name', [])),
            ])
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

    private function putIfPresent(array &$values, string $key, mixed $value): void
    {
        if ($value === null || $value === []) {
            return;
        }

        $values[$key] = $value;
    }
}
