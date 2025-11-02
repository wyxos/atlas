<?php

namespace App\Http\Controllers;

use App\Models\SpotifyToken;
use App\Support\SpotifyClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SpotifyAuthController extends Controller
{
    private const AUTHORIZE_URL = 'https://accounts.spotify.com/authorize';

    private const TOKEN_URL = 'https://accounts.spotify.com/api/token';

    private const CONNECT_SCOPES = 'streaming user-read-email user-read-private user-library-read user-read-playback-state user-modify-playback-state';

    public function __construct(private SpotifyClient $spotifyClient) {}

    public function connect(Request $request): RedirectResponse
    {
        $clientId = (string) (config('services.spotify.client_id') ?? '');
        if ($clientId === '') {
            abort(500, 'Missing SPOTIFY_CLIENT_ID');
        }

        $redirectUri = (string) (config('services.spotify.redirect') ?: route('spotify.callback'));

        $state = Str::random(40);
        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);

        $request->session()->put('spotify_oauth_state', $state);
        $request->session()->put('spotify_code_verifier', $codeVerifier);

        $returnTo = $request->query('return_to');
        if (is_string($returnTo) && $returnTo !== '' && str_starts_with($returnTo, '/')) {
            $request->session()->put('spotify_return_to', $returnTo);
        } else {
            $request->session()->forget('spotify_return_to');
        }

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'scope' => self::CONNECT_SCOPES,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'code_challenge_method' => 'S256',
            'code_challenge' => $codeChallenge,
        ], '', '&', PHP_QUERY_RFC3986);

        return redirect()->away(self::AUTHORIZE_URL.'?'.$query);
    }

    public function callback(Request $request): RedirectResponse
    {
        $expectedState = (string) $request->session()->pull('spotify_oauth_state', '');
        $actualState = (string) $request->query('state', '');
        $authorizationCode = (string) $request->query('code', '');

        if ($expectedState === '' || $actualState === '' || ! hash_equals($expectedState, $actualState)) {
            return $this->errorRedirect($request, 'Invalid OAuth state.');
        }

        if ($authorizationCode === '') {
            return $this->errorRedirect($request, 'Missing authorization code.');
        }

        $user = $request->user();
        if (! $user) {
            return $this->errorRedirect($request, 'Authentication required.');
        }

        $clientId = (string) (config('services.spotify.client_id') ?? '');
        if ($clientId === '') {
            return $this->errorRedirect($request, 'Missing SPOTIFY_CLIENT_ID.');
        }

        $redirectUri = (string) (config('services.spotify.redirect') ?: route('spotify.callback'));
        $codeVerifier = (string) $request->session()->pull('spotify_code_verifier', '');
        if ($codeVerifier === '') {
            return $this->errorRedirect($request, 'Missing PKCE verifier.');
        }

        $tokenResponse = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $authorizationCode,
            'redirect_uri' => $redirectUri,
            'client_id' => $clientId,
            'code_verifier' => $codeVerifier,
        ]);

        if (! $tokenResponse->ok()) {
            Log::warning('Spotify token exchange failed', [
                'status' => $tokenResponse->status(),
                'body' => $tokenResponse->json(),
            ]);

            return $this->errorRedirect($request, 'Failed to exchange token with Spotify.');
        }

        $payload = (array) $tokenResponse->json();
        $accessToken = (string) ($payload['access_token'] ?? '');
        if ($accessToken === '') {
            return $this->errorRedirect($request, 'Missing access token from Spotify.');
        }

        $refreshToken = (string) ($payload['refresh_token'] ?? '');
        $expiresIn = (int) ($payload['expires_in'] ?? 3600);
        $scope = (string) ($payload['scope'] ?? '');

        $attributes = [
            'access_token' => $accessToken,
            'expires_at' => now()->addSeconds(max(0, $expiresIn - 30)),
            'scope' => $scope !== '' ? $scope : null,
        ];
        if ($refreshToken !== '') {
            $attributes['refresh_token'] = $refreshToken;
        }

        SpotifyToken::updateOrCreate(['user_id' => $user->id], $attributes);

        $destination = $this->intendedRedirect($request);

        return redirect()->to($destination)->with('success', 'Spotify connected.');
    }

    public function token(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $accessToken = $this->spotifyClient->getAccessTokenForUser($user->id);
        if ($accessToken === null) {
            return response()->json(['error' => 'Not connected'], 401);
        }

        $record = SpotifyToken::where('user_id', $user->id)->first();

        return response()->json([
            'access_token' => $accessToken,
            'scope' => (string) ($record?->scope ?? ''),
            'expires_at' => $record?->expires_at?->toIso8601String(),
        ]);
    }

    public function disconnect(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        SpotifyToken::where('user_id', $user->id)->delete();

        return response()->json(['message' => 'Spotify disconnected successfully']);
    }

    private function intendedRedirect(Request $request): string
    {
        $returnTo = (string) $request->session()->pull('spotify_return_to', '');
        if ($returnTo !== '' && str_starts_with($returnTo, '/')) {
            return url($returnTo);
        }

        return route('spotify.edit');
    }

    private function errorRedirect(Request $request, string $message): RedirectResponse
    {
        return redirect()->to($this->intendedRedirect($request))->with('error', $message);
    }

    private function generateCodeVerifier(): string
    {
        $random = random_bytes(64);

        return $this->base64UrlEncode($random);
    }

    private function generateCodeChallenge(string $codeVerifier): string
    {
        return $this->base64UrlEncode(hash('sha256', $codeVerifier, true));
    }

    private function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
