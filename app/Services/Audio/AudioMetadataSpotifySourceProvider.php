<?php

namespace App\Services\Audio;

use App\Models\File;
use App\Models\User;
use App\Services\Spotify\SpotifyOAuthConfig;
use App\Services\Spotify\SpotifyOAuthService;
use Illuminate\Support\Facades\Http;

class AudioMetadataSpotifySourceProvider
{
    public function __construct(
        private readonly SpotifyOAuthConfig $spotifyConfig,
        private readonly SpotifyOAuthService $spotifyOAuth,
        private readonly AudioMetadataValueExtractor $values,
    ) {}

    /**
     * @return array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}
     */
    public function candidate(File $file, User $user, ?callable $progress = null): array
    {
        $trackId = $this->trackId((string) $file->source_id)
            ?? $this->trackId((string) $file->url)
            ?? $this->trackId((string) $file->referrer_url);
        $track = null;
        $evidence = ['source' => 'spotify', 'track_id' => $trackId, 'refetched' => false];

        if ($trackId !== null) {
            $this->reportProgress($progress, 'spotify', 'Refreshing Spotify metadata');
            $accessToken = $this->spotifyOAuth->getValidAccessToken($user);
            if ($accessToken !== null) {
                $track = $this->fetchTrack($trackId, $accessToken);
                $evidence['refetched'] = $track !== null;
            }
        }

        if ($track === null) {
            $listingTrack = data_get($file->listing_metadata, 'track');
            $track = is_array($listingTrack) ? $listingTrack : [];
            $evidence['source'] = 'spotify_listing_metadata';
        }

        $values = $this->values($track);
        if (($values['spotify_uri'] ?? null) === null && $trackId !== null) {
            $values['spotify_uri'] = 'spotify:track:'.$trackId;
        }

        return [
            'provider' => 'spotify',
            'confidence' => $evidence['refetched'] ? 98 : 70,
            'values' => $values,
            'evidence' => $evidence,
        ];
    }

    public function uriForFile(File $file): ?string
    {
        $trackId = $this->trackId((string) $file->source_id)
            ?? $this->trackId((string) $file->url)
            ?? $this->trackId((string) $file->referrer_url);

        return $trackId !== null ? 'spotify:track:'.$trackId : null;
    }

    private function fetchTrack(string $trackId, string $accessToken): ?array
    {
        $response = Http::acceptJson()
            ->withToken($accessToken)
            ->timeout(15)
            ->get(rtrim($this->spotifyConfig->apiBaseUrl(), '/').'/tracks/'.$trackId);

        if (! $response->successful()) {
            return null;
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param  array<string, mixed>  $track
     * @return array<string, mixed>
     */
    private function values(array $track): array
    {
        $values = [];
        $this->putIfPresent($values, 'title', $this->values->cleanString(data_get($track, 'name')));
        $this->putIfPresent($values, 'artists', $this->values->cleanStringList(data_get($track, 'artists.*.name', [])));
        $this->putIfPresent($values, 'album', $this->values->cleanString(data_get($track, 'album.name')));
        $this->putIfPresent($values, 'spotify_uri', $this->values->cleanString(data_get($track, 'uri')));
        $this->putIfPresent($values, 'isrc', $this->values->cleanString(data_get($track, 'external_ids.isrc')));
        $this->putIfPresent($values, 'cover_url', $this->bestCoverUrl(data_get($track, 'album.images', [])));
        $this->putIfPresent($values, 'track_number', $this->values->cleanString(data_get($track, 'track_number')));
        $this->putIfPresent($values, 'disc_number', $this->values->cleanString(data_get($track, 'disc_number')));
        $this->putIfPresent($values, 'release_date', $this->values->cleanString(data_get($track, 'album.release_date')));

        $duration = $this->values->positiveInteger(data_get($track, 'duration_ms'));
        if ($duration !== null) {
            $values['duration_seconds'] = (int) round($duration / 1000);
        }

        return $values;
    }

    private function putIfPresent(array &$values, string $key, mixed $value): void
    {
        if ($value === null || $value === []) {
            return;
        }

        $values[$key] = $value;
    }

    private function bestCoverUrl(mixed $images): ?string
    {
        if (! is_array($images)) {
            return null;
        }

        $sortedImages = collect($images)
            ->filter(fn (mixed $image): bool => is_array($image) && $this->values->cleanString($image['url'] ?? null) !== null)
            ->sortByDesc(fn (array $image): int => (int) ($image['width'] ?? 0))
            ->values();

        return $this->values->cleanString($sortedImages->first()['url'] ?? null);
    }

    private function trackId(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^spotify:track:([A-Za-z0-9]{22})$/', $value, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/^[A-Za-z0-9]{22}$/', $value) === 1) {
            return $value;
        }

        if (preg_match('#open\.spotify\.com/track/([A-Za-z0-9]{22})#', $value, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function reportProgress(?callable $progress, string $step, string $label): void
    {
        if ($progress === null) {
            return;
        }

        $progress($step, $label);
    }
}
