<?php

namespace App\Services\Audio;

use Illuminate\Support\Facades\Http;
use Throwable;

class AudioMetadataDiscogsClient
{
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
                if (is_array($result) && ($id = $this->releaseIdFromSearchResult($result)) !== null) {
                    $ids[] = $id;
                }
            }

            if ($ids !== []) {
                break;
            }
        }

        return collect($ids)->unique()->values()->all();
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
    private function releaseIdFromSearchResult(array $result): ?string
    {
        $id = $this->values->cleanString($result['id'] ?? null);
        if ($id === null) {
            return null;
        }

        $type = mb_strtolower((string) ($result['type'] ?? ''));
        $resourceUrl = $this->values->cleanString($result['resource_url'] ?? null);
        $masterUrl = $this->values->cleanString($result['master_url'] ?? null);
        if ($type !== 'master'
            && ! str_contains((string) $resourceUrl, '/masters/')
            && ! str_contains((string) $masterUrl, '/masters/')) {
            return $id;
        }

        return $this->releaseIdFromMasterResult($result);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function releaseIdFromMasterResult(array $result): ?string
    {
        $masterId = $this->values->cleanString($result['master_id'] ?? $result['id'] ?? null);
        if ($masterId === null) {
            return null;
        }

        $master = $this->fetchMaster($masterId);
        $releaseId = $this->values->cleanString($master['main_release'] ?? null);
        if ($releaseId === null) {
            return null;
        }

        $masterUri = $this->discogsWebUrl($this->values->cleanString($master['uri'] ?? $result['uri'] ?? null))
            ?? 'https://www.discogs.com/master/'.$masterId;
        $this->masterUrisByReleaseId[$releaseId] = $masterUri;

        return $releaseId;
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
                'type' => 'release',
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
                'type' => 'release',
                'q' => $releaseTitle,
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
                'type' => 'master',
                'q' => trim($artist.' '.$releaseTitle),
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
