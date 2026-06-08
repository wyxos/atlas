<?php

namespace App\Services\Audio;

use App\Models\File;

class AudioMetadataDiscogsProvider
{
    public function __construct(
        private readonly AudioMetadataAiReviewer $aiReviewer,
        private readonly AudioMetadataDiscogsClient $discogs,
        private readonly AudioMetadataDiscogsReleaseMetadataExtractor $releaseMetadata,
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

        $match = $this->bestReleaseMatch($file, $currentValues, $album, $artists, $title, $duration);
        if ($match === null) {
            return null;
        }

        $values = $this->releaseMetadata->valuesFromRelease(
            $match['release'],
            $match['track'],
            $match['evidence']['matched_existing_fields'] ?? [],
        );
        $values = $this->releaseMetadata->valuesAllowedByReleaseAdjudication($values, $match['evidence']['release_adjudication'] ?? null);
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
    private function bestReleaseMatch(File $file, array $currentValues, string $album, array $artists, ?string $title, ?int $duration): ?array
    {
        $candidates = [];
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

                $candidates[] = $candidate;
            }
        }

        if ($candidates === []) {
            return null;
        }

        $best = $this->bestDeterministicCandidate($candidates);
        if (count($candidates) < 2 || ! $this->aiReviewer->enabled()) {
            return $best;
        }

        return $this->aiSelectedReleaseMatch($file, $currentValues, $candidates);
    }

    /**
     * @param  list<array{release:array<string, mixed>,track:array<string, mixed>|null,confidence:int,evidence:array<string, mixed>}>  $candidates
     * @return array{release:array<string, mixed>,track:array<string, mixed>|null,confidence:int,evidence:array<string, mixed>}
     */
    private function bestDeterministicCandidate(array $candidates): array
    {
        $best = $candidates[0];

        foreach (array_slice($candidates, 1) as $candidate) {
            if ($this->candidateBeats($candidate, $best)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  list<array{release:array<string, mixed>,track:array<string, mixed>|null,confidence:int,evidence:array<string, mixed>}>  $candidates
     * @return array{release:array<string, mixed>,track:array<string, mixed>|null,confidence:int,evidence:array<string, mixed>}|null
     */
    private function aiSelectedReleaseMatch(File $file, array $currentValues, array $candidates): ?array
    {
        $review = $this->aiReviewer->adjudicateDiscogsRelease(
            $file,
            $currentValues,
            $this->releaseMetadata->adjudicationCandidates($candidates),
        );

        if (($review['verdict'] ?? null) !== 'accept') {
            return null;
        }

        $selectedReleaseId = $this->values->cleanString($review['selected_release_id'] ?? null);
        if ($selectedReleaseId === null) {
            return null;
        }

        foreach ($candidates as $candidate) {
            $candidateReleaseId = $this->values->cleanString($candidate['evidence']['discogs_release_id'] ?? null);
            if ($candidateReleaseId !== $selectedReleaseId) {
                continue;
            }

            $selectedTrackPosition = $this->values->cleanString($review['selected_track_position'] ?? null);
            $candidateTrackPosition = $this->values->cleanString($candidate['evidence']['track_position'] ?? null);
            if ($selectedTrackPosition !== null && $candidateTrackPosition !== null && $selectedTrackPosition !== $candidateTrackPosition) {
                return null;
            }

            $candidate['evidence']['release_adjudication'] = $review;
            if (is_numeric($review['confidence'] ?? null)) {
                $candidate['confidence'] = max(72, min(96, (int) round(((float) $review['confidence']) * 100)));
            }

            return $candidate;
        }

        return null;
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
        $releaseArtists = $this->releaseMetadata->releaseArtists($release);
        $track = $this->trackMatcher->bestTrack($release, $title, $duration);
        $trackDuration = $this->releaseMetadata->durationSeconds($track['duration'] ?? null);
        $durationDelta = $duration !== null && $trackDuration !== null ? abs($duration - $trackDuration) : null;
        $coverUrl = $this->releaseMetadata->coverUrl($release);

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

        if ($this->releaseMetadata->firstBarcode($release) !== null) {
            $confidence += 2;
        }

        if ($this->releaseMetadata->firstCatalogNumber($release) !== null) {
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
                'discogs_release_url' => $this->releaseMetadata->discogsReleaseUrl($release, $releaseId),
                'discogs_master_id' => $this->values->cleanString($release['master_id'] ?? null),
                'discogs_master_url' => $this->releaseMetadata->discogsMasterUrl($release),
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

    private function artistsOverlap(array $left, array $right): bool
    {
        $left = array_map(fn (string $name): string => $this->normalizeName($name), $left);
        $right = array_map(fn (string $name): string => $this->normalizeName($name), $right);

        return array_values(array_intersect($left, $right)) !== [];
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
