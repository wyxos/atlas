<?php

namespace App\Services\Audio;

use App\Models\File;
use Illuminate\Support\Facades\Http;
use Throwable;

class AudioMetadataDiscogsProvider
{
    public function __construct(
        private readonly AudioMetadataValueExtractor $values,
    ) {}

    /**
     * @param  array<string, mixed>  $currentValues
     * @return array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}|null
     */
    public function candidate(File $file, array $currentValues): ?array
    {
        if ($this->token() === null) {
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

        $values = $this->valuesFromRelease($match['release'], $match['track']);
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

        foreach ($this->releaseSearchTitles($album) as $releaseTitle) {
            foreach ($this->searchReleaseIds($releaseTitle, $artists[0]) as $releaseId) {
                $release = $this->fetchRelease($releaseId);
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

                if ($best === null || $candidate['confidence'] > $best['confidence']) {
                    $best = $candidate;
                }
            }
        }

        return $best;
    }

    private function searchReleaseIds(string $releaseTitle, string $artist): array
    {
        $ids = [];
        foreach ([
            [
                'type' => 'release',
                'artist' => $artist,
                'release_title' => $releaseTitle,
                'per_page' => 5,
                'page' => 1,
            ],
            [
                'type' => 'release',
                'q' => trim($artist.' '.$releaseTitle),
                'per_page' => 5,
                'page' => 1,
            ],
        ] as $query) {
            try {
                $response = Http::acceptJson()
                    ->withHeaders($this->headers())
                    ->timeout((int) config('services.audio_metadata.http_timeout_seconds', 15))
                    ->get(rtrim($this->baseUrl(), '/').'/database/search', $query);
            } catch (Throwable) {
                continue;
            }

            if (! $response->successful()) {
                continue;
            }

            $results = $response->json('results');
            if (! is_array($results)) {
                continue;
            }

            foreach ($results as $result) {
                if (is_array($result) && ($id = $this->values->cleanString($result['id'] ?? null)) !== null) {
                    $ids[] = $id;
                }
            }

            if ($ids !== []) {
                break;
            }
        }

        return collect($ids)->unique()->values()->all();
    }

    private function fetchRelease(string $releaseId): array
    {
        try {
            $response = Http::acceptJson()
                ->withHeaders($this->headers())
                ->timeout((int) config('services.audio_metadata.http_timeout_seconds', 15))
                ->get(rtrim($this->baseUrl(), '/').'/releases/'.$releaseId);
        } catch (Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
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
        $track = $this->bestTrack($release, $title, $duration);
        $trackDuration = $this->discogsDurationSeconds($track['duration'] ?? null);
        $durationDelta = $duration !== null && $trackDuration !== null ? abs($duration - $trackDuration) : null;

        if ($this->titlesMatch($album, $releaseTitle)) {
            $confidence += 10;
            $matchedFields[] = 'album';
        } else {
            $confidence -= 10;
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
                $confidence -= 6;
            }
        }

        if ($this->firstBarcode($release) !== null) {
            $confidence += 2;
        }

        if ($this->firstCatalogNumber($release) !== null) {
            $confidence += 2;
        }

        $releaseId = $this->values->cleanString($release['id'] ?? null);

        return [
            'confidence' => max(55, min(90, $confidence)),
            'track' => $track,
            'evidence' => [
                'source' => 'discogs_release_search',
                'discogs_release_id' => $releaseId,
                'discogs_release_title' => $releaseTitle,
                'discogs_release_url' => $releaseId !== null ? 'https://www.discogs.com/release/'.$releaseId : null,
                'matched_existing_fields' => $matchedFields,
                'duration_delta_seconds' => $durationDelta,
                'track_position' => $this->values->cleanString($track['position'] ?? null),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $release
     * @param  array<string, mixed>|null  $track
     * @return array<string, mixed>
     */
    private function valuesFromRelease(array $release, ?array $track): array
    {
        $values = [];

        $this->putIfPresent($values, 'album', $this->values->cleanString($release['title'] ?? null));
        $this->putIfPresent($values, 'artists', $this->trackArtists($track) ?: $this->releaseArtists($release));
        $this->putIfPresent($values, 'release_label', $this->firstLabelName($release));
        $this->putIfPresent($values, 'catalog_number', $this->firstCatalogNumber($release));
        $this->putIfPresent($values, 'barcode', $this->firstBarcode($release));
        $this->putIfPresent($values, 'release_date', $this->values->cleanString($release['released'] ?? $release['year'] ?? null));
        $this->putIfPresent($values, 'release_country', $this->values->cleanString($release['country'] ?? null));
        $this->putIfPresent($values, 'discogs_release_id', $this->values->cleanString($release['id'] ?? null));

        if ($track !== null) {
            $this->putIfPresent($values, 'title', $this->values->cleanString($track['title'] ?? null));
            $this->putIfPresent($values, 'track_number', $this->trackNumber($track));
            $this->putIfPresent($values, 'disc_number', $this->discNumber($track));
            $this->putIfPresent($values, 'duration_seconds', $this->discogsDurationSeconds($track['duration'] ?? null));
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $release
     * @return array<string, mixed>|null
     */
    private function bestTrack(array $release, ?string $title, ?int $duration): ?array
    {
        if ($title === null) {
            return null;
        }

        $tracks = $release['tracklist'] ?? null;
        if (! is_array($tracks)) {
            return null;
        }

        $best = null;
        $bestScore = 0;

        foreach ($tracks as $track) {
            if (! is_array($track)) {
                continue;
            }

            $trackTitle = $this->values->cleanString($track['title'] ?? null);
            if ($trackTitle === null) {
                continue;
            }

            $score = $this->trackMatchScore($title, $trackTitle);
            $trackDuration = $this->discogsDurationSeconds($track['duration'] ?? null);
            if ($duration !== null && $trackDuration !== null && abs($duration - $trackDuration) <= 5) {
                $score += 2;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $track;
            }
        }

        return $bestScore >= 8 ? $best : null;
    }

    private function trackMatchScore(string $title, string $trackTitle): int
    {
        $left = $this->normalizeTrackTitle($title);
        $right = $this->normalizeTrackTitle($trackTitle);
        if ($left === '' || $right === '') {
            return 0;
        }

        if ($left === $right) {
            return 10;
        }

        return str_contains($left, $right) || str_contains($right, $left) ? 8 : 0;
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
            && ($left === $right || str_contains($left, $right) || str_contains($right, $left));
    }

    private function releaseSearchTitles(string $album): array
    {
        $clean = $this->values->cleanString($album);
        if ($clean === null) {
            return [];
        }

        $withoutSceneSuffix = preg_replace('/[-_\s]*\([A-Z0-9-]{2,}\)[-_\s]*(WEB|CD|VINYL|FLAC|MP3)?$/i', '', $clean) ?? $clean;
        $withoutFormatSuffix = preg_replace('/[-_\s]+(WEB|CD|VINYL|FLAC|MP3)$/i', '', $withoutSceneSuffix) ?? $withoutSceneSuffix;
        $spaced = str_replace(['__', '_'], ' ', $withoutFormatSuffix);
        $withoutEpSuffix = preg_replace('/\s+EP$/i', '', $spaced) ?? $spaced;

        return collect([$clean, $withoutSceneSuffix, $withoutFormatSuffix, $spaced, $withoutEpSuffix])
            ->map(fn (string $candidate): ?string => $this->values->cleanString($candidate))
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

    private function normalizeTrackTitle(string $title): string
    {
        $title = preg_replace('/^\s*\d+\s*[-_.]\s*/', '', $title) ?? $title;

        return trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($title)) ?? '');
    }

    private function normalizeName(string $name): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($name)) ?? '';
    }

    private function headers(): array
    {
        return [
            'Authorization' => 'Discogs token='.$this->token(),
            'User-Agent' => $this->userAgent(),
        ];
    }

    private function token(): ?string
    {
        return $this->values->cleanString(config('services.audio_metadata.discogs_user_token'));
    }

    private function baseUrl(): string
    {
        return (string) config('services.audio_metadata.discogs_api_base_url', 'https://api.discogs.com');
    }

    private function userAgent(): string
    {
        return (string) config('services.audio_metadata.user_agent', 'Atlas/1.0');
    }
}
