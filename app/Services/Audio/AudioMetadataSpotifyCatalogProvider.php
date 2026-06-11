<?php

namespace App\Services\Audio;

use App\Models\File;
use App\Models\User;
use App\Services\Spotify\SpotifyOAuthConfig;
use App\Services\Spotify\SpotifyOAuthService;
use Illuminate\Support\Facades\Http;
use Throwable;

class AudioMetadataSpotifyCatalogProvider
{
    public function __construct(
        private readonly SpotifyOAuthConfig $config,
        private readonly SpotifyOAuthService $spotifyOAuth,
        private readonly AudioMetadataValueExtractor $values,
    ) {}

    /**
     * @param  array<string, mixed>  $currentValues
     * @return array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}|null
     */
    public function candidate(File $file, array $currentValues, User $user): ?array
    {
        if (! (bool) config('services.audio_metadata.spotify_catalog_enabled', true)) {
            return null;
        }

        $accessToken = $this->spotifyOAuth->getValidAccessToken($user);
        if ($accessToken === null) {
            return null;
        }

        $best = null;
        foreach ($this->searchQueries($file, $currentValues) as $query) {
            foreach ($this->searchTracks($query, $accessToken) as $track) {
                $candidate = $this->candidateFromTrack($track, $currentValues, $query);
                if ($candidate === null || $candidate['confidence'] < 68) {
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
        $isrc = $this->values->cleanString($currentValues['isrc'] ?? null);
        $title = $this->values->cleanString($currentValues['title'] ?? null)
            ?? $this->values->cleanString(pathinfo($file->filename, PATHINFO_FILENAME));
        $album = $this->values->cleanString($currentValues['album'] ?? null);
        $artists = $this->values->cleanStringList($currentValues['artists'] ?? []);
        $artist = $artists[0] ?? null;

        $queries = [];
        if ($isrc !== null) {
            $queries[] = 'isrc:'.$isrc;
        }

        if ($title !== null) {
            $queries[] = $this->filteredQuery($title, $artist, $album);
            $queries[] = trim(implode(' ', array_filter([$title, $artist, $album])));
        }

        return $this->uniqueStrings($queries, 4);
    }

    private function filteredQuery(string $title, ?string $artist, ?string $album): string
    {
        $parts = ['track:'.$this->quotedFilterValue($title)];
        if ($artist !== null) {
            $parts[] = 'artist:'.$this->quotedFilterValue($artist);
        }

        if ($album !== null) {
            $parts[] = 'album:'.$this->quotedFilterValue($album);
        }

        return implode(' ', $parts);
    }

    private function quotedFilterValue(string $value): string
    {
        return '"'.str_replace('"', ' ', $value).'"';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchTracks(string $query, string $accessToken): array
    {
        try {
            $response = Http::acceptJson()
                ->withToken($accessToken)
                ->timeout((int) config('services.audio_metadata.http_timeout_seconds', 15))
                ->get(rtrim($this->config->apiBaseUrl(), '/').'/search', [
                    'q' => $query,
                    'type' => 'track',
                    'limit' => 8,
                ]);
        } catch (Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $items = $response->json('tracks.items');
        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter($items, fn (mixed $item): bool => is_array($item)));
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
        if ($score['confidence'] < 1) {
            return null;
        }

        return [
            'provider' => 'spotify_catalog',
            'confidence' => min(92, $score['confidence']),
            'values' => $values,
            'evidence' => [
                'source' => 'spotify_catalog_search',
                'spotify_track_id' => $this->values->cleanString($track['id'] ?? null) ?? $this->spotifyIdFromUri($values['spotify_uri'] ?? null),
                'spotify_track_url' => $this->values->cleanString(data_get($track, 'external_urls.spotify')),
                'spotify_search_query' => $query,
                'matched_existing_fields' => $score['matched_fields'],
                'duration_delta_seconds' => $score['duration_delta_seconds'],
                'cover_source' => ($values['cover_url'] ?? null) !== null ? 'spotify' : null,
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
        $this->putIfPresent($values, 'title', $this->values->cleanString(data_get($track, 'name')));
        $this->putIfPresent($values, 'artists', $this->values->cleanStringList(data_get($track, 'artists.*.name', [])));
        $this->putIfPresent($values, 'album', $this->values->cleanString(data_get($track, 'album.name')));
        $this->putIfPresent($values, 'spotify_uri', $this->values->cleanString(data_get($track, 'uri')));
        $this->putIfPresent($values, 'isrc', $this->values->cleanString(data_get($track, 'external_ids.isrc')));
        $this->putIfPresent($values, 'cover_url', $this->coverUrl(data_get($track, 'album.images', [])));
        $this->putIfPresent($values, 'track_number', $this->values->cleanString(data_get($track, 'track_number')));
        $this->putIfPresent($values, 'disc_number', $this->values->cleanString(data_get($track, 'disc_number')));
        $this->putIfPresent($values, 'release_date', $this->values->cleanString(data_get($track, 'album.release_date')));

        $duration = $this->values->positiveInteger(data_get($track, 'duration_ms'));
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
        $confidence = 38;
        $matchedFields = [];

        if ($this->matches($candidateValues['isrc'] ?? null, $currentValues['isrc'] ?? null)) {
            $confidence += 28;
            $matchedFields[] = 'isrc';
        }

        if ($this->matches($candidateValues['title'] ?? null, $currentValues['title'] ?? null)) {
            $confidence += 18;
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

    private function coverUrl(mixed $images): ?string
    {
        if (! is_array($images)) {
            return null;
        }

        $best = collect($images)
            ->filter(fn (mixed $image): bool => is_array($image) && $this->values->cleanString($image['url'] ?? null) !== null)
            ->sortByDesc(fn (array $image): int => (int) ($image['width'] ?? 0))
            ->first();

        return is_array($best) ? $this->values->cleanString($best['url'] ?? null) : null;
    }

    private function spotifyIdFromUri(mixed $uri): ?string
    {
        $uri = $this->values->cleanString($uri);
        if ($uri === null) {
            return null;
        }

        return preg_match('/^spotify:track:([A-Za-z0-9]{22})$/', $uri, $matches) === 1 ? $matches[1] : null;
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
