<?php

namespace App\Services\DeviantArt;

use App\Models\DeviantArtToken;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class DeviantArtOAuthService
{
    private const SESSION_STATE_KEY = 'deviantart.oauth.state';

    private const SESSION_CODE_VERIFIER_KEY = 'deviantart.oauth.code_verifier';

    private const SESSION_USER_ID_KEY = 'deviantart.oauth.user_id';

    private const SESSION_STARTED_AT_KEY = 'deviantart.oauth.started_at';

    private const STATE_TTL_SECONDS = 900;

    public function __construct(
        private readonly DeviantArtOAuthConfig $config,
    ) {}

    public function statusForUser(User $user): array
    {
        $token = $this->tokenForUser($user);

        $base = [
            'key' => 'deviantart',
            'label' => 'DeviantArt',
            'configured' => $this->config->isConfigured(),
            'missing_configuration' => $this->config->missingConfiguration(),
            'connected' => $token !== null,
            'session_valid' => false,
            'needs_reconnect' => false,
            'can_refresh' => false,
            'scopes' => $this->normalizeScopes($token?->scope),
            'expires_at' => $token?->expires_at?->toISOString(),
            'expires_in_seconds' => $this->expiresInSeconds($token),
            'account' => $this->accountPayload($token),
            'last_error' => null,
            'connect_url' => '/auth/deviantart/redirect',
        ];

        if (! $token) {
            return $base;
        }

        try {
            $base['can_refresh'] = $this->decryptedTokenValueOrEmpty($token, 'refresh_token') !== '';
            $token = $this->refreshStoredToken($token);
            $accessToken = $this->decryptedTokenValueOrEmpty($token, 'access_token');

            $this->validateAccessToken($accessToken);

            if ($this->hasScope($token->scope, 'user')) {
                $token = $this->persistAccountProfile($token, $this->fetchCurrentUserProfile($accessToken));
            }

            $base['session_valid'] = true;
            $base['expires_at'] = $token->expires_at?->toISOString();
            $base['expires_in_seconds'] = $this->expiresInSeconds($token);
            $base['scopes'] = $this->normalizeScopes($token->scope);
            $base['account'] = $this->accountPayload($token);

            return $base;
        } catch (DeviantArtOAuthException $exception) {
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
            $base['last_error'] = 'Unable to validate DeviantArt session.';

            return $base;
        }
    }

    public function beginAuthorization(Request $request): string
    {
        if (! $this->config->isConfigured()) {
            throw new DeviantArtOAuthException('DeviantArt OAuth is not configured on this server.');
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
            'client_id' => $this->config->clientId(),
            'redirect_uri' => $this->config->redirectUri(),
            'scope' => implode(' ', $this->config->configuredScopes()),
            'state' => $state,
            'code_challenge_method' => 'S256',
            'code_challenge' => $codeChallenge,
        ], '', '&', PHP_QUERY_RFC3986);

        return rtrim($this->config->authorizeUrl(), '?').'?'.$query;
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

    public function connectWithAuthorizationCode(User $user, string $authorizationCode, string $codeVerifier): DeviantArtToken
    {
        if (! $this->config->isConfigured()) {
            throw new DeviantArtOAuthException('DeviantArt OAuth is not configured on this server.');
        }

        $response = $this->tokenHttpClient()
            ->post($this->config->tokenUrl(), [
                'grant_type' => 'authorization_code',
                'code' => $authorizationCode,
                'redirect_uri' => $this->config->redirectUri(),
                'client_id' => $this->config->clientId(),
                'client_secret' => $this->config->clientSecret(),
                'code_verifier' => $codeVerifier,
            ]);

        $token = $this->persistToken($user, $this->parseTokenResponse($response));
        $accessToken = $this->decryptedTokenValueOrEmpty($token, 'access_token');

        if ($this->hasScope($token->scope, 'user')) {
            $token = $this->persistAccountProfile($token, $this->fetchCurrentUserProfile($accessToken));
        }

        return $token;
    }

    public function refreshTokenForUser(User $user, bool $force = false): DeviantArtToken
    {
        $token = $this->tokenForUser($user);
        if (! $token) {
            throw new DeviantArtOAuthException('DeviantArt is not connected for this account.', true);
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

            return $this->decryptedTokenValueOrEmpty($token, 'access_token');
        } catch (DeviantArtOAuthException $exception) {
            if ($exception->requiresReconnect) {
                $this->disconnect($user);
            }

            return null;
        }
    }

    public function disconnect(User $user): void
    {
        DeviantArtToken::query()
            ->where('user_id', $user->id)
            ->delete();
    }

    private function refreshStoredToken(DeviantArtToken $token, bool $force = false): DeviantArtToken
    {
        if (! $force && ! $token->isExpired()) {
            return $token;
        }

        $refreshToken = $this->decryptedTokenValueOrEmpty($token, 'refresh_token');
        if ($refreshToken === '') {
            throw new DeviantArtOAuthException('Missing DeviantArt refresh token. Please reconnect DeviantArt.', true);
        }

        $response = $this->tokenHttpClient()
            ->post($this->config->tokenUrl(), [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $this->config->clientId(),
                'client_secret' => $this->config->clientSecret(),
            ]);

        if (! $response->successful()) {
            $message = $this->extractOAuthErrorMessage($response, 'Unable to refresh DeviantArt session.');
            throw new DeviantArtOAuthException($message, $this->shouldRequireReconnect($response));
        }

        $payload = $response->json();
        if (! is_array($payload) || trim((string) Arr::get($payload, 'access_token', '')) === '') {
            throw new DeviantArtOAuthException('DeviantArt refresh response did not include a valid access token.');
        }

        $user = $token->user()->first();
        if (! $user) {
            throw new DeviantArtOAuthException('DeviantArt token is not attached to a valid user account.', true);
        }

        return $this->persistToken($user, $payload);
    }

    private function persistToken(User $user, array $payload): DeviantArtToken
    {
        $accessToken = trim((string) Arr::get($payload, 'access_token', ''));
        if ($accessToken === '') {
            throw new DeviantArtOAuthException('DeviantArt token response did not include an access token.');
        }

        $existing = $this->tokenForUser($user);
        $refreshToken = trim((string) Arr::get($payload, 'refresh_token', ''));
        if ($refreshToken === '') {
            $refreshToken = $existing ? $this->decryptedTokenValueOrEmpty($existing, 'refresh_token') : '';
        }

        $scope = trim((string) Arr::get($payload, 'scope', ''));
        if ($scope === '') {
            $scope = trim((string) ($existing?->scope ?? implode(' ', $this->config->configuredScopes())));
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

            DeviantArtToken::query()
                ->where('user_id', $user->id)
                ->where('id', '!=', $existing->id)
                ->delete();

            return $existing->fresh();
        }

        return DeviantArtToken::query()->create([
            'user_id' => $user->id,
            ...$attributes,
        ]);
    }

    private function persistAccountProfile(DeviantArtToken $token, ?array $profile): DeviantArtToken
    {
        if (! $profile) {
            return $token;
        }

        $token->fill([
            'account_userid' => Arr::get($profile, 'userid'),
            'account_username' => Arr::get($profile, 'username'),
            'account_usericon' => Arr::get($profile, 'usericon'),
        ]);
        $token->save();

        return $token->fresh();
    }

    private function parseTokenResponse(Response $response): array
    {
        $payload = $response->json();
        if (! $response->successful()) {
            Log::warning('DeviantArt OAuth token request failed', [
                'status' => $response->status(),
                'error' => Arr::get($payload ?: [], 'error'),
                'error_description' => Arr::get($payload ?: [], 'error_description'),
                'redirect_uri' => $this->config->redirectUri(),
                'scopes' => $this->config->configuredScopes(),
            ]);

            throw new DeviantArtOAuthException(
                $this->extractOAuthErrorMessage($response, 'Unable to complete DeviantArt authorization.'),
                $this->shouldRequireReconnect($response),
            );
        }

        if (! is_array($payload)) {
            throw new DeviantArtOAuthException('DeviantArt token response was not valid JSON.');
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
        }

        return $fallback;
    }

    private function shouldRequireReconnect(Response $response): bool
    {
        $body = $response->json();
        if (! is_array($body)) {
            return $response->status() === 401;
        }

        $error = trim((string) Arr::get($body, 'error', ''));
        $errorDescription = trim((string) Arr::get($body, 'error_description', ''));

        return $response->status() === 401
            || $error === 'invalid_grant'
            || str_contains(strtolower($errorDescription), 'revoked');
    }

    private function validateAccessToken(string $accessToken): void
    {
        $response = $this->apiHttpClient($accessToken)
            ->post(rtrim($this->config->apiBaseUrl(), '/').'/placebo');

        if (! $response->successful()) {
            throw new DeviantArtOAuthException(
                $this->extractOAuthErrorMessage($response, 'Unable to validate DeviantArt session.'),
                $response->status() === 401,
            );
        }
    }

    private function fetchCurrentUserProfile(string $accessToken): ?array
    {
        $response = $this->apiHttpClient($accessToken)
            ->get(rtrim($this->config->apiBaseUrl(), '/').'/user/whoami');

        if (! $response->successful()) {
            throw new DeviantArtOAuthException(
                $this->extractOAuthErrorMessage($response, 'Unable to fetch DeviantArt profile.'),
                $response->status() === 401,
            );
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : null;
    }

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

    private function hasScope(?string $scope, string $expected): bool
    {
        return in_array($expected, $this->normalizeScopes($scope), true);
    }

    private function expiresInSeconds(?DeviantArtToken $token): ?int
    {
        if (! $token?->expires_at) {
            return null;
        }

        return max(0, now()->diffInSeconds($token->expires_at, false));
    }

    private function tokenForUser(User $user): ?DeviantArtToken
    {
        return DeviantArtToken::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();
    }

    private function accountPayload(?DeviantArtToken $token): ?array
    {
        if (! $token || (! $token->account_userid && ! $token->account_username)) {
            return null;
        }

        return [
            'userid' => $token->account_userid,
            'username' => $token->account_username,
            'usericon' => $token->account_usericon,
        ];
    }

    private function generateCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    }

    private function codeChallengeFromVerifier(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }

    private function tokenHttpClient(): PendingRequest
    {
        return Http::asForm()
            ->acceptJson()
            ->timeout(15);
    }

    private function apiHttpClient(string $accessToken): PendingRequest
    {
        return Http::acceptJson()
            ->withToken($accessToken)
            ->withHeaders([
                'Accept-Encoding' => 'gzip',
                'User-Agent' => config('services.deviantart.user_agent', 'Atlas/1.0 (+https://www.deviantart.com)'),
            ])
            ->timeout(15);
    }

    private function decryptedTokenValueOrEmpty(DeviantArtToken $token, string $attribute): string
    {
        try {
            return trim((string) $token->getAttribute($attribute));
        } catch (Throwable $exception) {
            throw new DeviantArtOAuthException('Stored DeviantArt credentials are invalid. Please reconnect DeviantArt.', true);
        }
    }
}
