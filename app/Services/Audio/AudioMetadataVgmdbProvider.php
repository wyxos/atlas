<?php

namespace App\Services\Audio;

use App\Models\File;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

class AudioMetadataVgmdbProvider
{
    public function __construct(private readonly AudioMetadataValueExtractor $values) {}

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}|null  $relatedCandidate
     * @return array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}|null
     */
    public function candidate(File $file, array $currentValues, ?array $relatedCandidate = null): ?array
    {
        if (! (bool) config('services.audio_metadata.vgmdb_enabled', true)) {
            return null;
        }

        $best = null;
        foreach ($this->searchQueries($file, $currentValues, $relatedCandidate) as $query) {
            $albums = $this->searchAlbums($query);
            if ($albums === null) {
                break;
            }

            foreach ($albums as $result) {
                $album = $this->fetchAlbum($this->albumLink($result));
                if ($album === []) {
                    continue;
                }

                $scored = $this->scoreAlbum($album, $currentValues, $relatedCandidate);
                if ($scored === null || $scored['confidence'] < 72) {
                    continue;
                }
                if ($best === null || $scored['confidence'] > $best['confidence']) {
                    $best = $scored;
                }
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}|null  $relatedCandidate
     * @return list<string>
     */
    private function searchQueries(File $file, array $currentValues, ?array $relatedCandidate): array
    {
        return $this->uniqueStrings([
            $relatedCandidate['values']['album'] ?? null,
            $currentValues['album'] ?? null,
            $relatedCandidate['values']['title'] ?? null,
            $currentValues['title'] ?? null,
            pathinfo($file->filename, PATHINFO_FILENAME),
        ], 6);
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function searchAlbums(string $query): ?array
    {
        try {
            $response = $this->http()
                ->get($this->baseUrl().'/search/albums', [
                    'format' => 'json',
                    'q' => $query,
                ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return [];
        }

        $albums = $response->json('results.albums');
        if (! is_array($albums)) {
            return [];
        }

        return array_values(array_filter($albums, fn (mixed $album): bool => is_array($album)));
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchAlbum(?string $link): array
    {
        if ($link === null) {
            return [];
        }

        try {
            $response = $this->http()
                ->get($this->baseUrl().'/'.$link, [
                    'format' => 'json',
                ]);
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
     * @param  array<string, mixed>  $album
     * @param  array<string, mixed>  $currentValues
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}|null  $relatedCandidate
     * @return array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}|null
     */
    private function scoreAlbum(array $album, array $currentValues, ?array $relatedCandidate): ?array
    {
        $albumNames = $this->names($album['names'] ?? [], $album['name'] ?? null);
        $albumCanonical = $this->canonicalName($albumNames);
        if ($albumCanonical === null) {
            return null;
        }

        $trackMatch = $this->bestTrackMatch($album, $currentValues, $relatedCandidate);
        $confidence = 58;
        $matchedFields = [];

        if ($this->matchesAny($albumNames, [
            $currentValues['album'] ?? null,
            $relatedCandidate['values']['album'] ?? null,
        ])) {
            $confidence += 17;
            $matchedFields[] = 'album';
        }

        if ($this->matchesAny($albumNames, [
            $currentValues['title'] ?? null,
            $relatedCandidate['values']['title'] ?? null,
        ])) {
            $confidence += 7;
            $matchedFields[] = 'title';
        }

        if ($trackMatch !== null) {
            $confidence += 13;
            $matchedFields[] = 'track';
            if (($trackMatch['duration_delta_seconds'] ?? null) !== null && $trackMatch['duration_delta_seconds'] <= 3) {
                $confidence += 3;
                $matchedFields[] = 'duration';
            }
        }

        if ($this->releaseYearMatches($album, $currentValues['release_date'] ?? null)) {
            $confidence += 3;
            $matchedFields[] = 'release_date';
        }

        if ($this->coverUrl($album) !== null) {
            $confidence += 3;
        }

        if ($this->values->cleanString($album['catalog'] ?? null) !== null) {
            $confidence += 2;
        }

        $values = $this->valuesFromAlbum($album, $albumNames, $trackMatch);
        if ($values === []) {
            return null;
        }

        return [
            'provider' => 'vgmdb_album',
            'confidence' => max(60, min(90, $confidence)),
            'values' => $values,
            'evidence' => [
                'source' => 'vgmdb_album_search',
                'vgmdb_album_id' => $this->vgmdbAlbumId($album),
                'vgmdb_album_link' => $this->values->cleanString($album['vgmdb_link'] ?? null),
                'matched_existing_fields' => array_values(array_unique($matchedFields)),
                'track_number' => $trackMatch['track_number'] ?? null,
                'duration_delta_seconds' => $trackMatch['duration_delta_seconds'] ?? null,
                'cover_source' => ($values['cover_url'] ?? null) !== null ? 'vgmdb' : null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $album
     * @param  list<string>  $albumNames
     * @param  array{track:array<string, mixed>,track_number:string,disc_number:string,duration_delta_seconds:int|null}|null  $trackMatch
     * @return array<string, mixed>
     */
    private function valuesFromAlbum(array $album, array $albumNames, ?array $trackMatch): array
    {
        $values = [];
        $albumCanonical = $this->canonicalName($albumNames);
        $this->putIfPresent($values, 'album', $albumCanonical);
        $this->putIfPresent($values, 'release_label', $this->firstNamedItem($album['publisher'] ?? null) ?? $this->firstNamedItem($album['distributor'] ?? null));
        $this->putIfPresent($values, 'catalog_number', $this->values->cleanString($album['catalog'] ?? null));
        $this->putIfPresent($values, 'release_date', $this->values->cleanString($album['release_date'] ?? null));
        $this->putIfPresent($values, 'cover_url', $this->coverUrl($album));
        $this->putIfPresent($values, 'artists', $this->albumArtists($album));

        if ($trackMatch !== null) {
            $trackNames = $this->names($trackMatch['track']['names'] ?? [], null);
            $trackCanonical = $this->canonicalName($trackNames);
            $this->putIfPresent($values, 'title', $trackCanonical);
            $this->putIfPresent($values, 'track_number', $trackMatch['track_number']);
            $this->putIfPresent($values, 'disc_number', $trackMatch['disc_number']);
            $this->putIfPresent($values, 'duration_seconds', $this->durationSeconds($trackMatch['track']['track_length'] ?? null));
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $album
     * @param  array<string, mixed>  $currentValues
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}|null  $relatedCandidate
     * @return array{track:array<string, mixed>,track_number:string,disc_number:string,duration_delta_seconds:int|null}|null
     */
    private function bestTrackMatch(array $album, array $currentValues, ?array $relatedCandidate): ?array
    {
        $titles = $this->uniqueStrings([
            $currentValues['title'] ?? null,
            $relatedCandidate['values']['title'] ?? null,
        ]);
        if ($titles === []) {
            return null;
        }

        $duration = $this->values->positiveInteger($currentValues['duration_seconds'] ?? null)
            ?? $this->values->positiveInteger($relatedCandidate['values']['duration_seconds'] ?? null);
        $best = null;

        foreach (($album['discs'] ?? []) as $discIndex => $disc) {
            if (! is_array($disc) || ! is_array($disc['tracks'] ?? null)) {
                continue;
            }

            foreach ($disc['tracks'] as $trackIndex => $track) {
                if (! is_array($track)) {
                    continue;
                }

                $trackNames = $this->names($track['names'] ?? [], null);
                if ($trackNames === []) {
                    continue;
                }

                $score = $this->matchesAny($trackNames, $titles) ? 10 : 0;
                $trackDuration = $this->durationSeconds($track['track_length'] ?? null);
                $durationDelta = $duration !== null && $trackDuration !== null ? abs($duration - $trackDuration) : null;
                if ($durationDelta !== null && $durationDelta <= 5) {
                    $score += 2;
                }

                if ($score < 8 || ($best !== null && $score <= $best['score'])) {
                    continue;
                }

                $best = [
                    'score' => $score,
                    'track' => $track,
                    'track_number' => (string) ($trackIndex + 1),
                    'disc_number' => (string) ($discIndex + 1),
                    'duration_delta_seconds' => $durationDelta,
                ];
            }
        }

        if ($best === null) {
            return null;
        }

        unset($best['score']);

        return $best;
    }

    private function albumArtists(array $album): array
    {
        foreach (['vocals', 'performers'] as $key) {
            $artists = collect(is_array($album[$key] ?? null) ? $album[$key] : [])
                ->map(fn (mixed $item): ?string => $this->firstNamedItem($item))
                ->filter()
                ->values()
                ->all();

            if ($artists !== []) {
                return $artists;
            }
        }

        return [];
    }

    private function coverUrl(array $album): ?string
    {
        $covers = collect(is_array($album['covers'] ?? null) ? $album['covers'] : [])
            ->filter(fn (mixed $cover): bool => is_array($cover));
        $front = $covers->first(fn (array $cover): bool => str_contains(mb_strtolower((string) ($cover['name'] ?? '')), 'front'))
            ?? $covers->first();

        if (is_array($front)) {
            return $this->values->cleanString($front['full'] ?? $front['medium'] ?? $front['thumb'] ?? null);
        }

        return $this->values->cleanString($album['picture_full'] ?? null)
            ?? $this->values->cleanString($album['picture_small'] ?? null)
            ?? $this->values->cleanString($album['picture_thumb'] ?? null);
    }

    private function firstNamedItem(mixed $item): ?string
    {
        if (! is_array($item)) {
            return null;
        }

        $names = $this->names($item['names'] ?? [], null);

        return $this->canonicalName($names);
    }

    private function names(mixed $names, mixed $fallback): array
    {
        if (! is_array($names)) {
            return $this->uniqueStrings([$fallback]);
        }

        return $this->uniqueStrings([
            $names['ja'] ?? null,
            $names['ja-jp'] ?? null,
            $names['jp'] ?? null,
            $names['ja-latn'] ?? null,
            $names['en'] ?? null,
            ...array_values($names),
            $fallback,
        ]);
    }

    private function canonicalName(array $names): ?string
    {
        return $this->values->cleanString($names[0] ?? null);
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function uniqueStrings(array $values, ?int $limit = null): array
    {
        $unique = [];
        foreach ($values as $value) {
            $clean = $this->values->cleanString($value);
            if ($clean !== null) {
                $unique[$this->normalizedStringKey($clean)] = $clean;
            }
        }

        $strings = array_values($unique);

        return $limit === null ? $strings : array_slice($strings, 0, $limit);
    }

    private function matchesAny(array $left, array $right): bool
    {
        $left = array_map(fn (string $value): string => $this->normalizedIdentity($value), $this->values->cleanStringList($left));
        $right = array_map(fn (string $value): string => $this->normalizedIdentity($value), $this->values->cleanStringList($right));

        return array_values(array_intersect($left, $right)) !== [];
    }

    private function releaseYearMatches(array $album, mixed $year): bool
    {
        $releaseDate = $this->values->cleanString($album['release_date'] ?? null);
        $year = $this->values->cleanString($year);

        return $releaseDate !== null
            && $year !== null
            && preg_match('/^\d{4}/', $releaseDate, $releaseMatches) === 1
            && preg_match('/^\d{4}/', $year, $hintMatches) === 1
            && $releaseMatches[0] === $hintMatches[0];
    }

    private function durationSeconds(mixed $duration): ?int
    {
        $duration = $this->values->cleanString($duration);
        if ($duration === null || ! str_contains($duration, ':')) {
            return null;
        }

        $parts = array_map('intval', explode(':', $duration));
        if (count($parts) !== 2) {
            return null;
        }

        return ($parts[0] * 60) + $parts[1];
    }

    private function albumLink(array $result): ?string
    {
        $link = $this->values->cleanString($result['link'] ?? null);
        if ($link === null || preg_match('#^album/\d+$#', $link) !== 1) {
            return null;
        }

        return $link;
    }

    private function vgmdbAlbumId(array $album): ?string
    {
        $link = $this->values->cleanString($album['link'] ?? null);
        if ($link !== null && preg_match('#^album/(\d+)$#', $link, $matches) === 1) {
            return $matches[1];
        }

        $link = $this->values->cleanString($album['vgmdb_link'] ?? null);
        if ($link !== null && preg_match('#/album/(\d+)#', $link, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function normalizedIdentity(string $value): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($value)) ?? '';
    }

    private function normalizedStringKey(string $value): string
    {
        return preg_replace('/\s+/u', ' ', mb_strtolower(trim($value))) ?? '';
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()
            ->withHeaders(['User-Agent' => $this->userAgent()])
            ->timeout($this->timeoutSeconds());
    }

    private function timeoutSeconds(): int
    {
        $timeout = config('services.audio_metadata.vgmdb_timeout_seconds', 8);

        return is_numeric($timeout) ? max(2, (int) $timeout) : 8;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.audio_metadata.vgmdb_api_base_url', 'https://vgmdb.info'), '/');
    }

    private function userAgent(): string
    {
        return (string) config('services.audio_metadata.user_agent', 'Atlas/1.0');
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
