<?php

namespace App\Services\DeviantArt;

class DeviantArtOAuthConfig
{
    public function isConfigured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '' && $this->redirectUri() !== '';
    }

    /**
     * @return list<string>
     */
    public function missingConfiguration(): array
    {
        $missing = [];
        if ($this->clientId() === '') {
            $missing[] = 'DEVIANTART_CLIENT_ID';
        }

        if ($this->clientSecret() === '') {
            $missing[] = 'DEVIANTART_CLIENT_SECRET';
        }

        if ($this->redirectUri() === '') {
            $missing[] = 'DEVIANTART_REDIRECT_URI';
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    public function configuredScopes(): array
    {
        $rawScopes = config('services.deviantart.scopes', '');
        if (is_array($rawScopes)) {
            $scopes = array_values(array_filter(array_map(
                static fn ($scope): string => trim((string) $scope),
                $rawScopes
            ), static fn (string $scope): bool => $scope !== ''));

            return $this->withBasicScope($scopes);
        }

        $value = trim((string) $rawScopes);
        if ($value === '') {
            return ['basic'];
        }

        $parts = preg_split('/[\s,]+/', $value) ?: [];

        $scopes = array_values(array_filter(array_unique(array_map(
            static fn ($scope): string => trim((string) $scope),
            $parts
        )), static fn (string $scope): bool => $scope !== ''));

        return $this->withBasicScope($scopes);
    }

    public function clientId(): string
    {
        return trim((string) config('services.deviantart.client_id', ''));
    }

    public function clientSecret(): string
    {
        return trim((string) config('services.deviantart.client_secret', ''));
    }

    public function redirectUri(): string
    {
        return trim((string) config('services.deviantart.redirect_uri', ''));
    }

    public function authorizeUrl(): string
    {
        return trim((string) config('services.deviantart.authorize_url', 'https://www.deviantart.com/oauth2/authorize'));
    }

    public function tokenUrl(): string
    {
        return trim((string) config('services.deviantart.token_url', 'https://www.deviantart.com/oauth2/token'));
    }

    public function apiBaseUrl(): string
    {
        return trim((string) config('services.deviantart.api_base_url', 'https://www.deviantart.com/api/v1/oauth2'));
    }

    /**
     * @param  list<string>  $scopes
     * @return list<string>
     */
    private function withBasicScope(array $scopes): array
    {
        if (! in_array('basic', $scopes, true)) {
            array_unshift($scopes, 'basic');
        }

        return array_values(array_unique($scopes));
    }
}
