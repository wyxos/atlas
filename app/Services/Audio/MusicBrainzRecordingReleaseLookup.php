<?php

namespace App\Services\Audio;

use Illuminate\Support\Facades\Http;
use Throwable;

class MusicBrainzRecordingReleaseLookup
{
    public function __construct(
        private readonly AudioMetadataValueExtractor $values,
    ) {}

    /**
     * @param  array<string, mixed>  $recording
     * @return array<string, mixed>
     */
    public function withReleases(array $recording): array
    {
        if (
            is_array($recording['releases'] ?? null)
            && $recording['releases'] !== []
            && $this->hasArtistAliasData($recording)
        ) {
            return $recording;
        }

        $recordingId = $this->cleanString($recording['id'] ?? null);
        if ($recordingId === null) {
            return $recording;
        }

        $details = $this->fetchRecording($recordingId);
        if ($details === []) {
            return $recording;
        }

        foreach (['title', 'length', 'duration', 'artist-credit'] as $key) {
            if (array_key_exists($key, $details)) {
                $recording[$key] = $details[$key];
            }
        }

        if (is_array($details['releases'] ?? null)) {
            $recording['releases'] = $details['releases'];
        }

        return $recording;
    }

    /**
     * @param  array<string, mixed>  $recording
     */
    private function hasArtistAliasData(array $recording): bool
    {
        $artistCredits = data_get($recording, 'artist-credit', []);
        if (! is_array($artistCredits)) {
            return false;
        }

        foreach ($artistCredits as $credit) {
            if (
                is_array($credit)
                && (
                    $this->cleanString(data_get($credit, 'artist.sort-name')) !== null
                    || is_array(data_get($credit, 'artist.aliases'))
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $recording
     * @param  array<string, mixed>  $hints
     * @return array<string, mixed>
     */
    public function bestRelease(array $recording, array $hints): array
    {
        $releases = $recording['releases'] ?? null;
        if (! is_array($releases)) {
            return [];
        }

        $release = collect($releases)
            ->filter(fn (mixed $release): bool => is_array($release) && $this->cleanString($release['title'] ?? null) !== null)
            ->sortByDesc(fn (array $release): int => $this->releaseScore($release, $recording, $hints))
            ->first();

        return is_array($release) ? $release : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchRecording(string $recordingId): array
    {
        try {
            $response = Http::acceptJson()
                ->withHeaders(['User-Agent' => $this->userAgent()])
                ->timeout((int) config('services.audio_metadata.http_timeout_seconds', 15))
                ->get(rtrim($this->musicBrainzBaseUrl(), '/').'/ws/2/recording/'.$recordingId, [
                    'fmt' => 'json',
                    'inc' => 'releases artist-credits',
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
     * @param  array<string, mixed>  $release
     * @param  array<string, mixed>  $recording
     * @param  array<string, mixed>  $hints
     */
    private function releaseScore(array $release, array $recording, array $hints): int
    {
        $score = $this->cleanString($release['id'] ?? null) !== null ? 4 : 0;
        $releaseTitle = $this->cleanString($release['title'] ?? null);
        $recordingTitle = $this->cleanString($recording['title'] ?? null);
        $status = mb_strtolower((string) ($release['status'] ?? ''));

        if ($status === 'official') {
            $score += 8;
        } elseif ($status === 'pseudo-release') {
            $score -= 14;
        }

        if ($this->cleanString($release['disambiguation'] ?? null) === null) {
            $score += 2;
        }

        if ($this->stringsMatch($releaseTitle, $recordingTitle)) {
            $score += 30;
        }

        if ($this->stringsMatch($releaseTitle, $hints['album'] ?? null)) {
            $score += 18;
        }

        if ($this->stringsMatch($releaseTitle, $hints['title'] ?? null)) {
            $score += 10;
        }

        if ($this->artistsOverlap($this->recordingArtists($recording), $this->releaseArtists($release))) {
            $score += 6;
        }

        if ($this->artistsOverlap($hints['artists'] ?? [], $this->releaseArtists($release))) {
            $score += 4;
        }

        if ($this->releaseYearMatches($release, $hints['release_date'] ?? null)) {
            $score += 3;
        }

        return $score;
    }

    /**
     * @param  array<string, mixed>  $recording
     * @return list<string>
     */
    private function recordingArtists(array $recording): array
    {
        $artists = $this->values->cleanStringList(data_get($recording, 'artists.*.name', []));
        if ($artists !== []) {
            return $artists;
        }

        $artistCredits = data_get($recording, 'artist-credit', []);
        if (! is_array($artistCredits)) {
            return [];
        }

        return $this->values->cleanStringList(collect($artistCredits)
            ->map(fn (mixed $credit): mixed => is_array($credit) ? ($credit['name'] ?? data_get($credit, 'artist.name')) : null)
            ->all());
    }

    /**
     * @param  array<string, mixed>  $release
     * @return list<string>
     */
    private function releaseArtists(array $release): array
    {
        $artistCredits = data_get($release, 'artist-credit', []);
        if (! is_array($artistCredits)) {
            return [];
        }

        return $this->values->cleanStringList(collect($artistCredits)
            ->map(fn (mixed $credit): mixed => is_array($credit) ? ($credit['name'] ?? data_get($credit, 'artist.name')) : null)
            ->all());
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     */
    private function artistsOverlap(array $left, array $right): bool
    {
        $left = array_map(fn (string $name): string => $this->normalizeComparableString($name), $left);
        $right = array_map(fn (string $name): string => $this->normalizeComparableString($name), $right);

        return array_values(array_intersect($left, $right)) !== [];
    }

    private function stringsMatch(mixed $left, mixed $right): bool
    {
        $left = $this->cleanString($left);
        $right = $this->cleanString($right);

        return $left !== null
            && $right !== null
            && $this->normalizeComparableString($left) === $this->normalizeComparableString($right);
    }

    /**
     * @param  array<string, mixed>  $release
     */
    private function releaseYearMatches(array $release, mixed $year): bool
    {
        $releaseDate = $this->cleanString($release['date'] ?? null);
        $year = $this->cleanString($year);

        return $releaseDate !== null
            && $year !== null
            && preg_match('/^\d{4}/', $releaseDate, $releaseMatches) === 1
            && preg_match('/^\d{4}/', $year, $hintMatches) === 1
            && $releaseMatches[0] === $hintMatches[0];
    }

    private function normalizeComparableString(string $value): string
    {
        return preg_replace('/\s+/', ' ', mb_strtolower(trim($value))) ?? '';
    }

    private function cleanString(mixed $value): ?string
    {
        return $this->values->cleanString($value);
    }

    private function musicBrainzBaseUrl(): string
    {
        return (string) config('services.audio_metadata.musicbrainz_api_base_url', 'https://musicbrainz.org');
    }

    private function userAgent(): string
    {
        return (string) config('services.audio_metadata.user_agent', 'Atlas/1.0');
    }
}
