<?php

namespace App\Http\Controllers;

use App\Services\Spotify\SpotifyOAuthException;
use App\Services\Spotify\SpotifyOAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class SettingsServicesController extends Controller
{
    public function index(Request $request, SpotifyOAuthService $spotify): JsonResponse
    {
        return response()->json([
            'spotify' => $spotify->statusForUser($request->user()),
        ]);
    }

    public function spotifyRedirect(Request $request, SpotifyOAuthService $spotify): RedirectResponse
    {
        try {
            return redirect()->away($spotify->beginAuthorization($request));
        } catch (SpotifyOAuthException $exception) {
            return $this->redirectToSettings('error', 'not_configured');
        }
    }

    public function spotifyCallback(Request $request, SpotifyOAuthService $spotify): RedirectResponse
    {
        $oauthError = trim((string) $request->query('error', ''));
        if ($oauthError !== '') {
            return $this->redirectToSettings('error', $oauthError);
        }

        $state = trim((string) $request->query('state', ''));
        $authorizationCode = trim((string) $request->query('code', ''));
        if ($state === '' || $authorizationCode === '') {
            return $this->redirectToSettings('error', 'missing_code_or_state');
        }

        $codeVerifier = $spotify->consumeAuthorizationState($request, $state);
        if ($codeVerifier === null) {
            return $this->redirectToSettings('error', 'invalid_state');
        }

        try {
            $spotify->connectWithAuthorizationCode($request->user(), $authorizationCode, $codeVerifier);

            return $this->redirectToSettings('connected');
        } catch (SpotifyOAuthException $exception) {
            return $this->redirectToSettings('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return $this->redirectToSettings('error', 'token_exchange_failed');
        }
    }

    public function spotifyRefresh(Request $request, SpotifyOAuthService $spotify): JsonResponse
    {
        try {
            $spotify->refreshTokenForUser($request->user(), true);

            return response()->json([
                'spotify' => $spotify->statusForUser($request->user()),
                'message' => 'Spotify session refreshed.',
            ]);
        } catch (SpotifyOAuthException $exception) {
            $statusCode = $exception->requiresReconnect ? 409 : 422;

            return response()->json([
                'spotify' => $spotify->statusForUser($request->user()),
                'message' => $exception->getMessage(),
            ], $statusCode);
        }
    }

    public function spotifyDisconnect(Request $request, SpotifyOAuthService $spotify): JsonResponse
    {
        $spotify->disconnect($request->user());

        return response()->json([
            'spotify' => $spotify->statusForUser($request->user()),
            'message' => 'Spotify disconnected.',
        ]);
    }

    private function redirectToSettings(string $notice, ?string $reason = null): RedirectResponse
    {
        $query = [
            'spotify_notice' => $notice,
        ];

        if ($reason !== null && trim($reason) !== '') {
            $query['spotify_reason'] = Str::of($reason)->limit(120)->toString();
        }

        return redirect()->to('/settings?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }
}
