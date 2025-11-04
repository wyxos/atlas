<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SpotifySavedTracks extends BaseService
{
    public const KEY = 'spotify-saved-tracks';

    public const SOURCE = 'Spotify';

    public const LABEL = 'Spotify Saved Tracks';

    public function defaultParams(): array
    {
        return [
            'limit' => 50,
            'offset' => 0,
            'market' => 'from_token',
        ];
    }

    public function formatParams(): array
    {
        $limit = isset($this->params['limit']) ? (int) $this->params['limit'] : 50;
        $limit = max(1, min(50, $limit));
        $offset = isset($this->params['offset']) ? (int) $this->params['offset'] : 0;
        $market = $this->params['market'] ?? null;

        $query = [
            'limit' => $limit,
            'offset' => $offset,
        ];
        if ($market) {
            $query['market'] = (string) $market;
        }

        return $query;
    }

    /**
     * Fetch using an access token provided in params['access_token'].
     */
    public function fetch(array $params = []): array
    {
        $this->params = array_merge($this->defaultParams(), $params);
        $accessToken = (string) ($this->params['access_token'] ?? '');
        if ($accessToken === '') {
            throw new \InvalidArgumentException('Spotify access token is required');
        }

        $query = $this->formatParams();

        return Http::acceptJson()->withToken($accessToken)
            ->get('https://api.spotify.com/v1/me/tracks', $query)
            ->json();
    }

    /**
     * Transform Spotify saved tracks API response into our File+Metadata structure.
     */
    public function transform(array $response, array $params = []): array
    {
        if ($params !== []) {
            $this->params = array_merge($this->params, $params);
        }

        $rows = (array) ($response['items'] ?? []);
        $mapped = array_map(fn ($row) => $this->transformRow((array) $row), $rows);

        return [
            'files' => array_values(array_filter($mapped)),
            'filter' => [
                ...$this->params,
                'total' => (int) ($response['total'] ?? 0),
                'next' => $response['next'] ?? null,
                'offset' => (int) ($response['offset'] ?? ($this->params['offset'] ?? 0)),
                'limit' => (int) ($response['limit'] ?? ($this->params['limit'] ?? 50)),
            ],
        ];
    }

    protected function transformRow(array $row): ?array
    {
        $addedAt = (string) ($row['added_at'] ?? '');
        $track = (array) ($row['track'] ?? []);
        $id = (string) ($track['id'] ?? '');
        if ($id === '') {
            return null;
        }

        $now = Carbon::now();
        $name = (string) ($track['name'] ?? '');
        $artists = array_map(fn ($a) => (string) ($a['name'] ?? ''), (array) ($track['artists'] ?? []));
        $album = (array) ($track['album'] ?? []);
        $albumName = (string) ($album['name'] ?? '');
        $images = (array) ($album['images'] ?? []);
        $thumb = is_array($images) && isset($images[0]['url']) ? (string) $images[0]['url'] : null;
        $extUrl = (string) (data_get($track, 'external_urls.spotify') ?? 'https://open.spotify.com/track/'.$id);
        $referrer = 'https://open.spotify.com/track/'.$id;

        $file = [
            'source' => self::SOURCE,
            'source_id' => $id,
            'url' => $extUrl,
            'referrer_url' => $referrer,
            'filename' => Str::random(40),
            'ext' => 'spotify',
            'mime_type' => 'audio/spotify',
            'hash' => null,
            'title' => $name,
            'description' => $albumName,
            'thumbnail_url' => $thumb,
            'listing_metadata' => json_encode([
                'added_at' => $addedAt,
                'track' => $track,
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $metadata = [
            'file_referrer_url' => $referrer,
            'payload' => json_encode([
                'title' => $name,
                'added_at' => $addedAt,
                'duration_ms' => $track['duration_ms'] ?? null,
                'duration' => isset($track['duration_ms']) ? ((int) $track['duration_ms']) / 1000 : null,
                'explicit' => $track['explicit'] ?? null,
                'popularity' => $track['popularity'] ?? null,
                'preview_url' => $track['preview_url'] ?? null,
                'artists' => $artists,
                'album' => [
                    'id' => $album['id'] ?? null,
                    'name' => $albumName,
                    'release_date' => $album['release_date'] ?? null,
                    'images' => $album['images'] ?? null,
                ],
                'external_urls' => $track['external_urls'] ?? null,
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        return [
            'file' => $file,
            'metadata' => $metadata,
        ];
    }

    public function containers(array $listingMetadata = [], array $detailMetadata = []): array
    {
        $track = (array) ($listingMetadata['track'] ?? []);
        $album = (array) ($track['album'] ?? []);
        $artistNames = array_map(fn ($a) => (string) ($a['name'] ?? ''), (array) ($track['artists'] ?? []));

        return [
            ['label' => 'album', 'key' => 'album', 'value' => $album['name'] ?? null],
            ['label' => 'artist', 'key' => 'artists', 'value' => implode(', ', array_filter($artistNames))],
        ];
    }
}
