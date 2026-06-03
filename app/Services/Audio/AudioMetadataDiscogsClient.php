<?php

namespace App\Services\Audio;

use Illuminate\Support\Facades\Http;
use Throwable;

class AudioMetadataDiscogsClient
{
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
                if (is_array($result) && ($id = $this->values->cleanString($result['id'] ?? null)) !== null) {
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
        ];
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
