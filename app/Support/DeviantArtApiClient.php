<?php

namespace App\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DeviantArtApiClient
{
    public function apiBaseUrl(): string
    {
        return rtrim((string) config('services.deviantart.api_base_url', 'https://www.deviantart.com/api/v1/oauth2'), '/');
    }

    public function requestJson(string $url, array $query, string $token): array
    {
        $response = $this->request($url, $query, $token);

        if ($response->failed()) {
            return $this->emptyResponse();
        }

        $json = $response->json();

        return is_array($json) ? $json : $this->emptyResponse();
    }

    public function downloadPayload(string $deviationId, string $token): array
    {
        return $this->requestJson($this->downloadUrl($deviationId), [], $token);
    }

    private function request(string $url, array $query, string $token): Response
    {
        if (! HttpRateLimiter::throttleDomain('deviantart.com', 80, 60, maxWaitSeconds: 1)) {
            throw new RuntimeException('DeviantArt API rate limit is active. Try again shortly.');
        }

        $response = HttpRateLimiter::requestWithRetry(
            fn () => Http::withToken($token)
                ->withHeaders($this->headers())
                ->acceptJson()
                ->connectTimeout(4)
                ->timeout(8),
            $url,
            $query,
            maxRetries: 0,
        );

        if ($response->status() === 429) {
            Log::warning('DeviantArt API rate limited', [
                'status' => 429,
                'retry_after' => $response->header('Retry-After'),
                'url' => $url,
            ]);
        }

        return $response;
    }

    private function downloadUrl(string $deviationId): string
    {
        return $this->apiBaseUrl().'/deviation/download/'.rawurlencode($deviationId);
    }

    private function headers(): array
    {
        return [
            'Accept-Encoding' => 'gzip',
            'User-Agent' => config('services.deviantart.user_agent', 'Atlas/1.0 (+https://www.deviantart.com)'),
        ];
    }

    private function emptyResponse(): array
    {
        return [
            'has_more' => false,
            'next_offset' => null,
            'results' => [],
        ];
    }
}
