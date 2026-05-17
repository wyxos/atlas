<?php

namespace App\Services\Spotify;

class SpotifyOAuthConfig
{
    public function isConfigured(): bool
    {
        return $this->clientId() !== '' && $this->redirectUri() !== '';
    }

    /**
     * @return list<string>
     */
    public function missingConfiguration(): array
    {
        $missing = [];
        if ($this->clientId() === '') {
            $missing[] = 'SPOTIFY_CLIENT_ID';
        }

        if ($this->redirectUri() === '') {
            $missing[] = 'SPOTIFY_REDIRECT_URI';
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    public function configuredScopes(): array
    {
        $rawScopes = config('services.spotify.scopes', '');
        if (is_array($rawScopes)) {
            return array_values(array_filter(array_map(
                static fn ($scope): string => trim((string) $scope),
                $rawScopes
            ), static fn (string $scope): bool => $scope !== ''));
        }

        $value = trim((string) $rawScopes);
        if ($value === '') {
            return [];
        }

        $parts = preg_split('/[\s,]+/', $value) ?: [];

        return array_values(array_filter(array_unique(array_map(
            static fn ($scope): string => trim((string) $scope),
            $parts
        )), static fn (string $scope): bool => $scope !== ''));
    }

    public function clientId(): string
    {
        return trim((string) config('services.spotify.client_id', ''));
    }

    public function clientSecret(): string
    {
        return trim((string) config('services.spotify.client_secret', ''));
    }

    public function redirectUri(): string
    {
        return trim((string) config('services.spotify.redirect_uri', ''));
    }

    public function authorizeUrl(): string
    {
        return trim((string) config('services.spotify.authorize_url', 'https://accounts.spotify.com/authorize'));
    }

    public function tokenUrl(): string
    {
        return trim((string) config('services.spotify.token_url', 'https://accounts.spotify.com/api/token'));
    }

    public function apiBaseUrl(): string
    {
        return trim((string) config('services.spotify.api_base_url', 'https://api.spotify.com/v1'));
    }
}
