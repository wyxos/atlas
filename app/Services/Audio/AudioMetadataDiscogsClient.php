<?php

namespace App\Services\Audio;

use Illuminate\Support\Facades\Http;
use Throwable;

class AudioMetadataDiscogsClient
{
    private const MAX_SEARCH_RELEASE_IDS = 16;

    private const MAX_MASTER_VERSION_RELEASE_IDS = 12;

    /**
     * @var array<string, string>
     */
    private array $masterUrisByReleaseId = [];

    public function __construct(
        private readonly AudioMetadataValueExtractor $values,
    ) {}

    public function configured(): bool
    {
        return $this->token() !== null;
    }

    /**
     * @return list<string>
     */
    public function searchReleaseIds(string $releaseTitle, string $artist): array
    {
        $ids = [];
        foreach ($this->searchQueries($releaseTitle, $artist) as $query) {
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
                if (! is_array($result)) {
                    continue;
                }

                foreach ($this->releaseIdsFromSearchResult($result) as $id) {
                    $ids[$id] = $id;

                    if (count($ids) >= self::MAX_SEARCH_RELEASE_IDS) {
                        return array_values($ids);
                    }
                }
            }

            if ($ids !== [] && ($query['type'] ?? null) === 'master') {
                break;
            }
        }

        return array_values($ids);
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchRelease(string $releaseId): array
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

        if (! is_array($payload)) {
            return [];
        }

        $releaseId = $this->values->cleanString($payload['id'] ?? $releaseId);
        if ($releaseId !== null && isset($this->masterUrisByReleaseId[$releaseId])) {
            $payload['discogs_master_uri'] = $this->masterUrisByReleaseId[$releaseId];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function releaseIdsFromSearchResult(array $result): array
    {
        $id = $this->values->cleanString($result['id'] ?? null);
        if ($id === null) {
            return [];
        }

        $type = mb_strtolower((string) ($result['type'] ?? ''));
        $resourceUrl = $this->values->cleanString($result['resource_url'] ?? null);
        $masterUrl = $this->values->cleanString($result['master_url'] ?? null);
        if ($type !== 'master'
            && ! str_contains((string) $resourceUrl, '/masters/')
            && ! str_contains((string) $masterUrl, '/masters/')) {
            return [$id];
        }

        return $this->releaseIdsFromMasterResult($result);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function releaseIdsFromMasterResult(array $result): array
    {
        $masterId = $this->values->cleanString($result['master_id'] ?? $result['id'] ?? null);
        if ($masterId === null) {
            return [];
        }

        $master = $this->fetchMaster($masterId);
        $masterUri = $this->discogsWebUrl($this->values->cleanString($master['uri'] ?? $result['uri'] ?? null))
            ?? 'https://www.discogs.com/master/'.$masterId;

        $ids = [];
        $mainReleaseId = $this->values->cleanString($master['main_release'] ?? null);
        if ($mainReleaseId !== null) {
            $ids[$mainReleaseId] = $mainReleaseId;
        }

        foreach ($this->fetchMasterVersionReleaseIds($masterId) as $versionReleaseId) {
            $ids[$versionReleaseId] = $versionReleaseId;
        }

        foreach ($ids as $releaseId) {
            $this->masterUrisByReleaseId[$releaseId] = $masterUri;
        }

        return array_values($ids);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchMaster(string $masterId): array
    {
        try {
            $response = Http::acceptJson()
                ->withHeaders($this->headers())
                ->timeout((int) config('services.audio_metadata.http_timeout_seconds', 15))
                ->get(rtrim($this->baseUrl(), '/').'/masters/'.$masterId);
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
     * @return list<string>
     */
    private function fetchMasterVersionReleaseIds(string $masterId): array
    {
        try {
            $response = Http::acceptJson()
                ->withHeaders($this->headers())
                ->timeout((int) config('services.audio_metadata.http_timeout_seconds', 15))
                ->get(rtrim($this->baseUrl(), '/').'/masters/'.$masterId.'/versions', [
                    'per_page' => 25,
                    'page' => 1,
                ]);
        } catch (Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $versions = $response->json('versions');
        if (! is_array($versions)) {
            return [];
        }

        $ids = [];
        foreach ($versions as $version) {
            if (! is_array($version)) {
                continue;
            }

            $releaseId = $this->values->cleanString($version['id'] ?? null);
            if ($releaseId === null) {
                continue;
            }

            $ids[$releaseId] = $releaseId;
            if (count($ids) >= self::MAX_MASTER_VERSION_RELEASE_IDS) {
                break;
            }
        }

        return array_values($ids);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchQueries(string $releaseTitle, string $artist): array
    {
        return [
            [
                'type' => 'release',
                'artist' => $artist,
                'release_title' => $releaseTitle,
                'per_page' => 5,
                'page' => 1,
            ],
            [
                'type' => 'master',
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
            [
                'type' => 'master',
                'q' => trim($artist.' '.$releaseTitle),
                'per_page' => 5,
                'page' => 1,
            ],
            [
                'type' => 'release',
                'release_title' => $releaseTitle,
                'per_page' => 5,
                'page' => 1,
            ],
            [
                'type' => 'master',
                'release_title' => $releaseTitle,
                'per_page' => 5,
                'page' => 1,
            ],
            [
                'type' => 'release',
                'q' => $releaseTitle,
                'per_page' => 5,
                'page' => 1,
            ],
            [
                'type' => 'master',
                'q' => $releaseTitle,
                'per_page' => 5,
                'page' => 1,
            ],
        ];
    }

    private function discogsWebUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        if (str_starts_with($url, 'https://www.discogs.com/')) {
            return $url;
        }

        if (str_starts_with($url, '/')) {
            return 'https://www.discogs.com'.$url;
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
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
