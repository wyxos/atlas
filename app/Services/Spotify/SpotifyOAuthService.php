<?php

namespace App\Services\Spotify;

use App\Models\SpotifyToken;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class SpotifyOAuthService
{
    private const SESSION_STATE_KEY = 'spotify.oauth.state';

    private const SESSION_CODE_VERIFIER_KEY = 'spotify.oauth.code_verifier';

    private const SESSION_USER_ID_KEY = 'spotify.oauth.user_id';

    private const SESSION_STARTED_AT_KEY = 'spotify.oauth.started_at';

    private const STATE_TTL_SECONDS = 900;

    public function statusForUser(User $user): array
    {
        $token = $this->tokenForUser($user);

        $base = [
            'key' => 'spotify',
            'label' => 'Spotify',
            'configured' => $this->isConfigured(),
            'missing_configuration' => $this->missingConfiguration(),
            'connected' => $token !== null,
            'session_valid' => false,
            'needs_reconnect' => false,
            'can_refresh' => $token?->refresh_token !== null && trim((string) $token->refresh_token) !== '',
            'scopes' => $this->normalizeScopes($token?->scope),
            'expires_at' => $token?->expires_at?->toISOString(),
            'expires_in_seconds' => $this->expiresInSeconds($token),
            'account' => null,
            'last_error' => null,
            'connect_url' => '/auth/spotify/redirect',
        ];

        if (! $token) {
            return $base;
        }

        try {
            $token = $this->refreshStoredToken($token);
            $base['session_valid'] = true;
            $base['expires_at'] = $token->expires_at?->toISOString();
            $base['expires_in_seconds'] = $this->expiresInSeconds($token);
            $base['scopes'] = $this->normalizeScopes($token->scope);
            $base['account'] = $this->fetchCurrentUserProfile($token->access_token);

            return $base;
        } catch (SpotifyOAuthException $exception) {
            if ($exception->requiresReconnect) {
                $this->disconnect($user);
                $base['connected'] = false;
                $base['can_refresh'] = false;
                $base['needs_reconnect'] = true;
            }

            $base['last_error'] = $exception->getMessage();

            return $base;
        } catch (Throwable $exception) {
            report($exception);
            $base['last_error'] = 'Unable to validate Spotify session.';

            return $base;
        }
    }

    public function beginAuthorization(Request $request): string
    {
        if (! $this->isConfigured()) {
            throw new SpotifyOAuthException('Spotify OAuth is not configured on this server.');
        }

        $state = Str::random(64);
        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->codeChallengeFromVerifier($codeVerifier);

        $request->session()->put([
            self::SESSION_STATE_KEY => $state,
            self::SESSION_CODE_VERIFIER_KEY => $codeVerifier,
            self::SESSION_USER_ID_KEY => (int) $request->user()->id,
            self::SESSION_STARTED_AT_KEY => now()->timestamp,
        ]);

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'scope' => implode(' ', $this->configuredScopes()),
            'state' => $state,
            'code_challenge_method' => 'S256',
            'code_challenge' => $codeChallenge,
            'show_dialog' => 'false',
        ], '', '&', PHP_QUERY_RFC3986);

        return rtrim($this->authorizeUrl(), '?').'?'.$query;
    }

    public function consumeAuthorizationState(Request $request, string $state): ?string
    {
        $expectedState = (string) $request->session()->pull(self::SESSION_STATE_KEY, '');
        $codeVerifier = (string) $request->session()->pull(self::SESSION_CODE_VERIFIER_KEY, '');
        $expectedUserId = (int) $request->session()->pull(self::SESSION_USER_ID_KEY, 0);
        $startedAt = (int) $request->session()->pull(self::SESSION_STARTED_AT_KEY, 0);
        $currentUserId = (int) $request->user()->id;

        if ($expectedState === '' || $codeVerifier === '') {
            return null;
        }

        if ($expectedUserId <= 0 || $expectedUserId !== $currentUserId) {
            return null;
        }

        if ($startedAt <= 0 || (now()->timestamp - $startedAt) > self::STATE_TTL_SECONDS) {
            return null;
        }

        if (! hash_equals($expectedState, $state)) {
            return null;
        }

        return $codeVerifier;
    }

    public function connectWithAuthorizationCode(User $user, string $authorizationCode, string $codeVerifier): SpotifyToken
    {
        if (! $this->isConfigured()) {
            throw new SpotifyOAuthException('Spotify OAuth is not configured on this server.');
        }

        $response = $this->tokenHttpClient()
            ->post($this->tokenUrl(), array_filter([
                'grant_type' => 'authorization_code',
                'code' => $authorizationCode,
                'redirect_uri' => $this->redirectUri(),
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret() ?: null,
                'code_verifier' => $codeVerifier,
            ], static fn ($value): bool => $value !== null && $value !== ''));

        $payload = $this->parseTokenResponse($response);

        return $this->persistToken($user, $payload);
    }

    public function refreshTokenForUser(User $user, bool $force = false): SpotifyToken
    {
        $token = $this->tokenForUser($user);
        if (! $token) {
            throw new SpotifyOAuthException('Spotify is not connected for this account.', true);
        }

        if (! $force && ! $token->isExpired()) {
            return $token;
        }

        return $this->refreshStoredToken($token, true);
    }

    public function getValidAccessToken(User $user): ?string
    {
        $token = $this->tokenForUser($user);
        if (! $token) {
            return null;
        }

        try {
            $token = $this->refreshStoredToken($token);
        } catch (SpotifyOAuthException $exception) {
            if ($exception->requiresReconnect) {
                $this->disconnect($user);
            }

            return null;
        }

        return $token->access_token;
    }

    public function disconnect(User $user): void
    {
        SpotifyToken::query()
            ->where('user_id', $user->id)
            ->delete();
    }

    private function refreshStoredToken(SpotifyToken $token, bool $force = false): SpotifyToken
    {
        if (! $force && ! $token->isExpired()) {
            return $token;
        }

        $refreshToken = trim((string) $token->refresh_token);
        if ($refreshToken === '') {
            throw new SpotifyOAuthException('Missing Spotify refresh token. Please reconnect Spotify.', true);
        }

        $response = $this->tokenHttpClient()
            ->post($this->tokenUrl(), array_filter([
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret() ?: null,
            ], static fn ($value): bool => $value !== null && $value !== ''));

        if (! $response->successful()) {
            $message = $this->extractOAuthErrorMessage($response, 'Unable to refresh Spotify session.');
            $requiresReconnect = $this->shouldRequireReconnect($response);
            throw new SpotifyOAuthException($message, $requiresReconnect);
        }

        $payload = $response->json();
        if (! is_array($payload) || trim((string) Arr::get($payload, 'access_token', '')) === '') {
            throw new SpotifyOAuthException('Spotify refresh response did not include a valid access token.');
        }

        $user = $token->user()->first();
        if (! $user) {
            throw new SpotifyOAuthException('Spotify token is not attached to a valid user account.', true);
        }

        return $this->persistToken($user, $payload);
    }

    private function persistToken(User $user, array $payload): SpotifyToken
    {
        $accessToken = trim((string) Arr::get($payload, 'access_token', ''));
        if ($accessToken === '') {
            throw new SpotifyOAuthException('Spotify token response did not include an access token.');
        }

        $existing = $this->tokenForUser($user);
        $refreshToken = trim((string) Arr::get($payload, 'refresh_token', ''));
        if ($refreshToken === '') {
            $refreshToken = trim((string) ($existing?->refresh_token ?? ''));
        }

        $scope = trim((string) Arr::get($payload, 'scope', ''));
        if ($scope === '') {
            $scope = trim((string) ($existing?->scope ?? implode(' ', $this->configuredScopes())));
        }

        $expiresIn = max(0, (int) Arr::get($payload, 'expires_in', 0));
        $expiresAt = $expiresIn > 0 ? now()->addSeconds(max(1, $expiresIn - 30)) : null;

        $attributes = [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken !== '' ? $refreshToken : null,
            'scope' => $scope !== '' ? $scope : null,
            'expires_at' => $expiresAt,
        ];

        if ($existing) {
            $existing->fill($attributes);
            $existing->save();

            SpotifyToken::query()
                ->where('user_id', $user->id)
                ->where('id', '!=', $existing->id)
                ->delete();

            return $existing->fresh();
        }

        return SpotifyToken::query()->create([
            'user_id' => $user->id,
            ...$attributes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseTokenResponse(Response $response): array
    {
        if (! $response->successful()) {
            throw new SpotifyOAuthException(
                $this->extractOAuthErrorMessage($response, 'Unable to complete Spotify authorization.'),
                $this->shouldRequireReconnect($response),
            );
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new SpotifyOAuthException('Spotify token response was not valid JSON.');
        }

        return $payload;
    }

    private function extractOAuthErrorMessage(Response $response, string $fallback): string
    {
        $body = $response->json();
        if (is_array($body)) {
            $description = trim((string) Arr::get($body, 'error_description', ''));
            if ($description !== '') {
                return $description;
            }

            $error = Arr::get($body, 'error');
            if (is_string($error) && trim($error) !== '') {
                return trim($error);
            }

            if (is_array($error)) {
                $message = trim((string) Arr::get($error, 'message', ''));
                if ($message !== '') {
                    return $message;
                }
            }
        }

        return $fallback;
    }

    private function shouldRequireReconnect(Response $response): bool
    {
        $body = $response->json();
        if (! is_array($body)) {
            return false;
        }

        $error = Arr::get($body, 'error');
        $errorDescription = trim((string) Arr::get($body, 'error_description', ''));

        if (is_string($error) && $error === 'invalid_grant') {
            return true;
        }

        if (is_array($error) && trim((string) Arr::get($error, 'status', '')) === '401') {
            return true;
        }

        return str_contains(strtolower($errorDescription), 'revoked');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchCurrentUserProfile(string $accessToken): ?array
    {
        $accessToken = trim($accessToken);
        if ($accessToken === '') {
            return null;
        }

        $response = Http::acceptJson()
            ->withToken($accessToken)
            ->timeout(15)
            ->get(rtrim($this->apiBaseUrl(), '/').'/me');

        if (! $response->successful()) {
            throw new SpotifyOAuthException(
                $this->extractOAuthErrorMessage($response, 'Unable to fetch Spotify profile.'),
                $response->status() === 401,
            );
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            return null;
        }

        return [
            'id' => Arr::get($payload, 'id'),
            'display_name' => Arr::get($payload, 'display_name'),
            'email' => Arr::get($payload, 'email'),
            'product' => Arr::get($payload, 'product'),
            'country' => Arr::get($payload, 'country'),
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeScopes(?string $scope): array
    {
        $scope = trim((string) $scope);
        if ($scope === '') {
            return [];
        }

        $parts = preg_split('/[\s,]+/', $scope) ?: [];

        return array_values(array_filter(array_unique(array_map(
            static fn ($value): string => trim((string) $value),
            $parts
        )), static fn (string $value): bool => $value !== ''));
    }

    private function expiresInSeconds(?SpotifyToken $token): ?int
    {
        if (! $token?->expires_at) {
            return null;
        }

        return max(0, now()->diffInSeconds($token->expires_at, false));
    }

    private function tokenForUser(User $user): ?SpotifyToken
    {
        return SpotifyToken::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();
    }

    private function generateCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    }

    private function codeChallengeFromVerifier(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }

    private function tokenHttpClient()
    {
        return Http::asForm()
            ->acceptJson()
            ->timeout(15);
    }

    private function isConfigured(): bool
    {
        return $this->clientId() !== '' && $this->redirectUri() !== '';
    }

    /**
     * @return list<string>
     */
    private function missingConfiguration(): array
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
    private function configuredScopes(): array
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

        return $this->normalizeScopes($value);
    }

    private function clientId(): string
    {
        return trim((string) config('services.spotify.client_id', ''));
    }

    private function clientSecret(): string
    {
        return trim((string) config('services.spotify.client_secret', ''));
    }

    private function redirectUri(): string
    {
        return trim((string) config('services.spotify.redirect_uri', ''));
    }

    private function authorizeUrl(): string
    {
        return trim((string) config('services.spotify.authorize_url', 'https://accounts.spotify.com/authorize'));
    }

    private function tokenUrl(): string
    {
        return trim((string) config('services.spotify.token_url', 'https://accounts.spotify.com/api/token'));
    }

    private function apiBaseUrl(): string
    {
        return trim((string) config('services.spotify.api_base_url', 'https://api.spotify.com/v1'));
    }
}
