<?php

namespace App\Services\Audio;

use App\Models\File;
use Illuminate\Support\Facades\Http;
use Throwable;

class AudioMetadataDeezerProvider
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
        if (! (bool) config('services.audio_metadata.deezer_enabled', true)) {
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
        $isrc = $this->values->cleanString($currentValues['isrc'] ?? null);

        return $this->uniqueStrings([
            $isrc !== null ? 'isrc:"'.$isrc.'"' : null,
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
            $params = [
                'q' => $query,
                'limit' => 10,
            ];
            $accessToken = $this->accessToken();
            if ($accessToken !== null) {
                $params['access_token'] = $accessToken;
            }

            $response = Http::acceptJson()
                ->timeout((int) config('services.audio_metadata.http_timeout_seconds', 15))
                ->get(rtrim($this->baseUrl(), '/').'/search/track', $params);
        } catch (Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $results = $response->json('data');
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
            'provider' => 'deezer',
            'confidence' => min(88, $score['confidence']),
            'values' => $values,
            'evidence' => [
                'source' => 'deezer_search',
                'deezer_track_id' => $this->values->cleanString($track['id'] ?? null),
                'deezer_album_id' => $this->values->cleanString(data_get($track, 'album.id')),
                'deezer_track_url' => $this->values->cleanString($track['link'] ?? null),
                'deezer_search_query' => $query,
                'matched_existing_fields' => $score['matched_fields'],
                'duration_delta_seconds' => $score['duration_delta_seconds'],
                'cover_source' => ($values['cover_url'] ?? null) !== null ? 'deezer' : null,
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
        $this->putIfPresent($values, 'title', $this->values->cleanString($track['title'] ?? null));
        $this->putIfPresent($values, 'artists', $this->values->cleanStringList(data_get($track, 'artist.name')));
        $this->putIfPresent($values, 'album', $this->values->cleanString(data_get($track, 'album.title')));
        $this->putIfPresent($values, 'isrc', $this->values->cleanString($track['isrc'] ?? null));
        $this->putIfPresent($values, 'cover_url', $this->coverUrl($track));
        $this->putIfPresent($values, 'track_number', $this->values->cleanString($track['track_position'] ?? null));
        $this->putIfPresent($values, 'disc_number', $this->values->cleanString($track['disk_number'] ?? null));

        $duration = $this->values->positiveInteger($track['duration'] ?? null);
        if ($duration !== null) {
            $values['duration_seconds'] = $duration;
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

        if ($this->matches($candidateValues['isrc'] ?? null, $currentValues['isrc'] ?? null)) {
            $confidence += 28;
            $matchedFields[] = 'isrc';
        }

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

    /**
     * @param  array<string, mixed>  $track
     */
    private function coverUrl(array $track): ?string
    {
        return $this->values->cleanString(data_get($track, 'album.cover_xl'))
            ?? $this->values->cleanString(data_get($track, 'album.cover_big'))
            ?? $this->values->cleanString(data_get($track, 'album.cover_medium'))
            ?? $this->values->cleanString(data_get($track, 'album.cover'));
    }

    private function baseUrl(): string
    {
        return trim((string) config('services.audio_metadata.deezer_api_base_url', 'https://api.deezer.com'));
    }

    private function accessToken(): ?string
    {
        return $this->values->cleanString(config('services.audio_metadata.deezer_access_token'));
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
