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

    public function deviationPayload(string $deviationId, string $token): array
    {
        return $this->requestJson($this->deviationUrl($deviationId), [], $token);
    }

    public function watchUser(string $username, string $token): bool
    {
        $response = $this->postForm($this->watchUrl($username), [
            'watch[friend]' => '1',
            'watch[deviations]' => '1',
            'watch[journals]' => '0',
            'watch[forum_threads]' => '0',
            'watch[critiques]' => '0',
            'watch[scraps]' => '0',
            'watch[activity]' => '1',
            'watch[collections]' => '0',
        ], $token);

        $payload = $response->json();

        return $response->successful()
            && is_array($payload)
            && ($payload['success'] ?? false) === true;
    }

    public function unwatchUser(string $username, string $token): bool
    {
        $response = $this->request($this->unwatchUrl($username), [], $token);
        $payload = $response->json();

        return $response->successful()
            && is_array($payload)
            && ($payload['success'] ?? false) === true;
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

    private function postForm(string $url, array $form, string $token): Response
    {
        if (! HttpRateLimiter::throttleDomain('deviantart.com', 80, 60, maxWaitSeconds: 1)) {
            throw new RuntimeException('DeviantArt API rate limit is active. Try again shortly.');
        }

        $response = Http::asForm()
            ->withToken($token)
            ->withHeaders($this->headers())
            ->acceptJson()
            ->connectTimeout(4)
            ->timeout(8)
            ->post($url, $form);

        if ($response->failed()) {
            Log::warning('DeviantArt API request failed', [
                'status' => $response->status(),
                'retry_after' => $response->header('Retry-After'),
                'url' => $url,
                'error' => $response->json('error'),
                'error_description' => $response->json('error_description'),
            ]);
        }

        return $response;
    }

    private function downloadUrl(string $deviationId): string
    {
        return $this->apiBaseUrl().'/deviation/download/'.rawurlencode($deviationId);
    }

    private function deviationUrl(string $deviationId): string
    {
        return $this->apiBaseUrl().'/deviation/'.rawurlencode($deviationId);
    }

    private function watchUrl(string $username): string
    {
        return $this->apiBaseUrl().'/user/friends/watch/'.rawurlencode($username);
    }

    private function unwatchUrl(string $username): string
    {
        return $this->apiBaseUrl().'/user/friends/unwatch/'.rawurlencode($username);
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
