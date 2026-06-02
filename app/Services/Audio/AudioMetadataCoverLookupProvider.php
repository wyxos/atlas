<?php

namespace App\Services\Audio;

use App\Models\File;
use Illuminate\Support\Facades\Http;
use Throwable;

class AudioMetadataCoverLookupProvider
{
    /**
     * @param  array<string, mixed>  $currentValues
     * @return array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}|null
     */
    public function candidate(File $file, array $currentValues): ?array
    {
        $album = $this->cleanString($currentValues['album'] ?? null);
        $artists = $this->cleanStringList($currentValues['artists'] ?? []);

        if ($album === null || $artists === []) {
            return null;
        }

        $release = $this->findRelease($album, $artists);
        if ($release === null) {
            return null;
        }

        $releaseId = $this->cleanString($release['id'] ?? null);
        $coverUrl = $this->coverUrlForRelease($releaseId);
        if ($releaseId === null || $coverUrl === null) {
            return null;
        }

        return [
            'provider' => 'musicbrainz_cover_art',
            'confidence' => 82,
            'values' => [
                'cover_url' => $coverUrl,
                'musicbrainz_release_id' => $releaseId,
            ],
            'evidence' => [
                'source' => 'musicbrainz_release_search',
                'matched_existing_fields' => ['artists', 'album'],
                'musicbrainz_release_id' => $releaseId,
                'musicbrainz_release_title' => $this->cleanString($release['title'] ?? null),
                'musicbrainz_release_artists' => $this->releaseArtists($release),
                'cover_source' => 'cover_art_archive',
            ],
        ];
    }

    /**
     * @param  list<string>  $artists
     * @return array<string, mixed>|null
     */
    private function findRelease(string $album, array $artists): ?array
    {
        $artist = $artists[0] ?? null;
        if ($artist === null) {
            return null;
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders(['User-Agent' => $this->userAgent()])
                ->timeout((int) config('services.audio_metadata.http_timeout_seconds', 15))
                ->get(rtrim($this->musicBrainzBaseUrl(), '/').'/ws/2/release', [
                    'fmt' => 'json',
                    'limit' => 10,
                    'query' => sprintf('artist:"%s" AND release:"%s"', $artist, $album),
                ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $releases = $response->json('releases');
        if (! is_array($releases)) {
            return null;
        }

        $release = collect($releases)
            ->filter(fn (mixed $release): bool => is_array($release)
                && $this->cleanString($release['id'] ?? null) !== null
                && $this->albumMatches($album, $release)
                && $this->artistsOverlap($artists, $this->releaseArtists($release)))
            ->sortByDesc(fn (array $release): int => (int) ($release['score'] ?? 0))
            ->first();

        return is_array($release) ? $release : null;
    }

    private function coverUrlForRelease(?string $releaseId): ?string
    {
        if ($releaseId === null) {
            return null;
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders(['User-Agent' => $this->userAgent()])
                ->timeout((int) config('services.audio_metadata.http_timeout_seconds', 15))
                ->get(rtrim($this->coverArtArchiveBaseUrl(), '/').'/release/'.$releaseId);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $images = $response->json('images');
        if (! is_array($images)) {
            return null;
        }

        $image = collect($images)
            ->filter(fn (mixed $image): bool => is_array($image) && (bool) ($image['front'] ?? false))
            ->first() ?? collect($images)->first();

        if (! is_array($image)) {
            return null;
        }

        return $this->cleanString(data_get($image, 'thumbnails.large'))
            ?? $this->cleanString(data_get($image, 'thumbnails.500'))
            ?? $this->cleanString($image['image'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $release
     */
    private function albumMatches(string $album, array $release): bool
    {
        $releaseTitle = $this->cleanString($release['title'] ?? null);
        if ($releaseTitle === null) {
            return false;
        }

        $needle = $this->normalizeTitle($album);
        $candidate = $this->normalizeTitle($releaseTitle);

        return $needle !== ''
            && $candidate !== ''
            && ($needle === $candidate || str_contains($candidate, $needle) || str_contains($needle, $candidate));
    }

    /**
     * @param  array<string, mixed>  $release
     * @return list<string>
     */
    private function releaseArtists(array $release): array
    {
        $artists = data_get($release, 'artist-credit.*.artist.name', []);
        $creditedNames = data_get($release, 'artist-credit.*.name', []);

        return $this->cleanStringList([...$artists, ...$creditedNames]);
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     */
    private function artistsOverlap(array $left, array $right): bool
    {
        $left = array_map(fn (string $name): string => $this->normalizeName($name), $left);
        $right = array_map(fn (string $name): string => $this->normalizeName($name), $right);

        return array_values(array_intersect($left, $right)) !== [];
    }

    /**
     * @return list<string>
     */
    private function cleanStringList(mixed $value): array
    {
        if (! is_array($value)) {
            $value = [$value];
        }

        $names = [];
        foreach ($value as $entry) {
            $clean = $this->cleanString($entry);
            if ($clean !== null) {
                $names[$this->normalizeName($clean)] = $clean;
            }
        }

        return array_values($names);
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $clean = preg_replace('/\s+/', ' ', trim((string) $value)) ?? '';

        return $clean !== '' ? $clean : null;
    }

    private function normalizeName(string $name): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($name)) ?? '';
    }

    private function normalizeTitle(string $title): string
    {
        $title = preg_replace('/\([^)]*\)|\[[^]]*]/', '', $title) ?? $title;

        return trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($title)) ?? '');
    }

    private function musicBrainzBaseUrl(): string
    {
        return (string) config('services.audio_metadata.musicbrainz_api_base_url', 'https://musicbrainz.org');
    }

    private function coverArtArchiveBaseUrl(): string
    {
        return (string) config('services.audio_metadata.cover_art_archive_base_url', 'https://coverartarchive.org');
    }

    private function userAgent(): string
    {
        return (string) config('services.audio_metadata.user_agent', 'Atlas/1.0');
    }
}
