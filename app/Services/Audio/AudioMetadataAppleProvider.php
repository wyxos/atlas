<?php

namespace App\Services\Audio;

use App\Models\File;
use Illuminate\Support\Facades\Http;
use Throwable;

class AudioMetadataAppleProvider
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
        if (! (bool) config('services.audio_metadata.apple_enabled', true)) {
            return null;
        }

        $best = null;
        foreach ($this->searchQueries($file, $currentValues) as $query) {
            foreach ($this->searchTracks($query) as $track) {
                $candidate = $this->candidateFromTrack($track, $currentValues, $query);
                if ($candidate === null || $candidate['confidence'] < 66) {
                    continue;
                }

                if ($best === null || $candidate['confidence'] > $best['confidence']) {
                    $best = $candidate;
                }
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $currentValues
     * @return list<string>
     */
    private function searchQueries(File $file, array $currentValues): array
    {
        $title = $this->values->cleanString($currentValues['title'] ?? null)
            ?? $this->values->cleanString(pathinfo($file->filename, PATHINFO_FILENAME));
        $album = $this->values->cleanString($currentValues['album'] ?? null);
        $artist = $this->values->cleanStringList($currentValues['artists'] ?? [])[0] ?? null;

        return $this->uniqueStrings([
            trim(implode(' ', array_filter([$title, $artist, $album]))),
            trim(implode(' ', array_filter([$title, $artist]))),
            $title,
        ], 4);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchTracks(string $query): array
    {
        try {
            $response = Http::acceptJson()
                ->timeout((int) config('services.audio_metadata.http_timeout_seconds', 15))
                ->get(rtrim($this->baseUrl(), '/').'/search', [
                    'term' => $query,
                    'media' => 'music',
                    'entity' => 'song',
                    'limit' => 10,
                    'country' => $this->country(),
                ]);
        } catch (Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $results = $response->json('results');
        if (! is_array($results)) {
            return [];
        }

        return array_values(array_filter($results, fn (mixed $result): bool => is_array($result)));
    }

    /**
     * @param  array<string, mixed>  $track
     * @param  array<string, mixed>  $currentValues
     * @return array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}|null
     */
    private function candidateFromTrack(array $track, array $currentValues, string $query): ?array
    {
        $values = $this->valuesFromTrack($track);
        if ($values === []) {
            return null;
        }

        $score = $this->score($values, $currentValues);

        return [
            'provider' => 'apple_music',
            'confidence' => min(88, $score['confidence']),
            'values' => $values,
            'evidence' => [
                'source' => 'apple_music_search',
                'apple_track_id' => $this->values->cleanString($track['trackId'] ?? null),
                'apple_collection_id' => $this->values->cleanString($track['collectionId'] ?? null),
                'apple_track_url' => $this->values->cleanString($track['trackViewUrl'] ?? null),
                'apple_collection_url' => $this->values->cleanString($track['collectionViewUrl'] ?? null),
                'apple_search_query' => $query,
                'matched_existing_fields' => $score['matched_fields'],
                'duration_delta_seconds' => $score['duration_delta_seconds'],
                'cover_source' => ($values['cover_url'] ?? null) !== null ? 'apple_music' : null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $track
     * @return array<string, mixed>
     */
    private function valuesFromTrack(array $track): array
    {
        $values = [];
        $this->putIfPresent($values, 'title', $this->values->cleanString($track['trackName'] ?? null));
        $this->putIfPresent($values, 'artists', $this->values->cleanStringList($track['artistName'] ?? null));
        $this->putIfPresent($values, 'album', $this->values->cleanString($track['collectionName'] ?? null));
        $this->putIfPresent($values, 'cover_url', $this->values->cleanString($track['artworkUrl100'] ?? null));
        $this->putIfPresent($values, 'track_number', $this->values->cleanString($track['trackNumber'] ?? null));
        $this->putIfPresent($values, 'disc_number', $this->values->cleanString($track['discNumber'] ?? null));
        $this->putIfPresent($values, 'release_date', $this->releaseDate($track['releaseDate'] ?? null));

        $duration = $this->values->positiveInteger($track['trackTimeMillis'] ?? null);
        if ($duration !== null) {
            $values['duration_seconds'] = (int) round($duration / 1000);
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $candidateValues
     * @param  array<string, mixed>  $currentValues
     * @return array{confidence:int,matched_fields:list<string>,duration_delta_seconds:int|null}
     */
    private function score(array $candidateValues, array $currentValues): array
    {
        $confidence = 36;
        $matchedFields = [];

        if ($this->matches($candidateValues['title'] ?? null, $currentValues['title'] ?? null)) {
            $confidence += 20;
            $matchedFields[] = 'track';
        }

        if ($this->matchesAny($candidateValues['artists'] ?? [], $currentValues['artists'] ?? [])) {
            $confidence += 12;
            $matchedFields[] = 'artists';
        }

        if ($this->matches($candidateValues['album'] ?? null, $currentValues['album'] ?? null)) {
            $confidence += 10;
            $matchedFields[] = 'album';
        }

        $durationDelta = $this->durationDelta($candidateValues['duration_seconds'] ?? null, $currentValues['duration_seconds'] ?? null);
        if ($durationDelta !== null && $durationDelta <= 3) {
            $confidence += 10;
            $matchedFields[] = 'duration';
        }

        if (($candidateValues['cover_url'] ?? null) !== null) {
            $confidence += 2;
        }

        return [
            'confidence' => $confidence,
            'matched_fields' => array_values(array_unique($matchedFields)),
            'duration_delta_seconds' => $durationDelta,
        ];
    }

    private function matches(mixed $candidate, mixed $current): bool
    {
        $candidate = $this->identity($candidate);
        $current = $this->identity($current);

        if ($candidate === '' || $current === '') {
            return false;
        }

        if ($candidate === $current) {
            return true;
        }

        return str_contains($candidate, $current) || str_contains($current, $candidate);
    }

    private function matchesAny(mixed $candidateValues, mixed $currentValues): bool
    {
        foreach ($this->values->cleanStringList($candidateValues) as $candidate) {
            foreach ($this->values->cleanStringList($currentValues) as $current) {
                if ($this->matches($candidate, $current)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function durationDelta(mixed $candidate, mixed $current): ?int
    {
        $candidate = $this->values->positiveInteger($candidate);
        $current = $this->values->positiveInteger($current);
        if ($candidate === null || $current === null) {
            return null;
        }

        return abs($candidate - $current);
    }

    private function identity(mixed $value): string
    {
        $clean = $this->values->cleanString($value);
        if ($clean === null) {
            return '';
        }

        $clean = preg_replace('/^\s*(?:the|a|an)\s+/iu', '', $clean) ?? $clean;

        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($clean)) ?? '';
    }

    private function releaseDate(mixed $value): ?string
    {
        $value = $this->values->cleanString($value);
        if ($value === null) {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $matches) === 1 ? $matches[0] : $value;
    }

    private function baseUrl(): string
    {
        return trim((string) config('services.audio_metadata.apple_api_base_url', 'https://itunes.apple.com'));
    }

    private function country(): string
    {
        $country = trim((string) config('services.audio_metadata.apple_country', 'US'));

        return $country !== '' ? $country : 'US';
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

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function uniqueStrings(array $values, int $limit): array
    {
        $unique = [];
        foreach ($values as $value) {
            $clean = $this->values->cleanString($value);
            if ($clean !== null) {
                $unique[mb_strtolower($clean)] = $clean;
            }
        }

        return array_slice(array_values($unique), 0, $limit);
    }
}
