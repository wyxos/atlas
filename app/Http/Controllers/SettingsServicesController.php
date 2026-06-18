<?php

namespace App\Http\Controllers;

use App\Services\DeviantArt\DeviantArtOAuthException;
use App\Services\DeviantArt\DeviantArtOAuthService;
use App\Services\ExtensionApiKeyService;
use App\Services\SettingsInfrastructureHealthService;
use App\Services\Spotify\SpotifyOAuthException;
use App\Services\Spotify\SpotifyOAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class SettingsServicesController extends Controller
{
    public function index(
        Request $request,
        SpotifyOAuthService $spotify,
        DeviantArtOAuthService $deviantArt,
        ExtensionApiKeyService $extensionApiKey,
    ): JsonResponse {
        return response()->json([
            'spotify' => $spotify->statusForUser($request->user()),
            'deviantart' => $deviantArt->statusForUser($request->user()),
            'extension' => [
                'api_key_configured' => $extensionApiKey->isConfigured(),
                'default_domain' => rtrim((string) config('app.url', 'https://atlas.test'), '/'),
            ],
        ]);
    }

    public function extensionApiKeyStore(Request $request, ExtensionApiKeyService $extensionApiKey): JsonResponse
    {
        $validated = $request->validate([
            'api_key' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        $extensionApiKey->save(trim((string) $validated['api_key']), (int) $request->user()->id);

        return response()->json([
            'api_key_configured' => true,
            'message' => 'Extension API key saved.',
        ]);
    }

    public function extensionApiKeyGenerate(Request $request, ExtensionApiKeyService $extensionApiKey): JsonResponse
    {
        $generatedApiKey = $extensionApiKey->generateAndSave((int) $request->user()->id);

        return response()->json([
            'api_key' => $generatedApiKey,
            'api_key_configured' => true,
            'message' => 'Extension API key generated.',
        ]);
    }

    public function infrastructureHealth(SettingsInfrastructureHealthService $health): JsonResponse
    {
        return response()->json($health->check());
    }

    public function spotifyRedirect(Request $request, SpotifyOAuthService $spotify): RedirectResponse
    {
        try {
            return redirect()->away($spotify->beginAuthorization($request));
        } catch (SpotifyOAuthException $exception) {
            return $this->redirectToSettings('spotify', 'error', 'not_configured');
        }
    }

    public function spotifyCallback(Request $request, SpotifyOAuthService $spotify): RedirectResponse
    {
        $oauthError = trim((string) $request->query('error', ''));
        if ($oauthError !== '') {
            return $this->redirectToSettings('spotify', 'error', $oauthError);
        }

        $state = trim((string) $request->query('state', ''));
        $authorizationCode = trim((string) $request->query('code', ''));
        if ($state === '' || $authorizationCode === '') {
            return $this->redirectToSettings('spotify', 'error', 'missing_code_or_state');
        }

        $codeVerifier = $spotify->consumeAuthorizationState($request, $state);
        if ($codeVerifier === null) {
            return $this->redirectToSettings('spotify', 'error', 'invalid_state');
        }

        try {
            $spotify->connectWithAuthorizationCode($request->user(), $authorizationCode, $codeVerifier);

            return $this->redirectToSettings('spotify', 'connected');
        } catch (SpotifyOAuthException $exception) {
            return $this->redirectToSettings('spotify', 'error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return $this->redirectToSettings('spotify', 'error', 'token_exchange_failed');
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
            $spotifyStatus = $spotify->statusForUser($request->user());

            if ($exception->requiresReconnect) {
                $spotifyStatus['needs_reconnect'] = true;
                $spotifyStatus['last_error'] = $exception->getMessage();
            }

            return response()->json([
                'spotify' => $spotifyStatus,
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

    public function deviantArtRedirect(Request $request, DeviantArtOAuthService $deviantArt): RedirectResponse
    {
        try {
            return redirect()->away($deviantArt->beginAuthorization($request));
        } catch (DeviantArtOAuthException $exception) {
            return $this->redirectToSettings('deviantart', 'error', 'not_configured');
        }
    }

    public function deviantArtCallback(Request $request, DeviantArtOAuthService $deviantArt): RedirectResponse
    {
        $oauthError = trim((string) $request->query('error', ''));
        if ($oauthError !== '') {
            $oauthDescription = trim((string) $request->query('error_description', ''));

            return $this->redirectToSettings('deviantart', 'error', $oauthDescription !== '' ? $oauthDescription : $oauthError);
        }

        $state = trim((string) $request->query('state', ''));
        $authorizationCode = trim((string) $request->query('code', ''));
        if ($state === '' || $authorizationCode === '') {
            return $this->redirectToSettings('deviantart', 'error', 'missing_code_or_state');
        }

        $codeVerifier = $deviantArt->consumeAuthorizationState($request, $state);
        if ($codeVerifier === null) {
            return $this->redirectToSettings('deviantart', 'error', 'invalid_state');
        }

        try {
            $deviantArt->connectWithAuthorizationCode($request->user(), $authorizationCode, $codeVerifier);

            return $this->redirectToSettings('deviantart', 'connected');
        } catch (DeviantArtOAuthException $exception) {
            return $this->redirectToSettings('deviantart', 'error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return $this->redirectToSettings('deviantart', 'error', 'token_exchange_failed');
        }
    }

    public function deviantArtRefresh(Request $request, DeviantArtOAuthService $deviantArt): JsonResponse
    {
        try {
            $deviantArt->refreshTokenForUser($request->user(), true);

            return response()->json([
                'deviantart' => $deviantArt->statusForUser($request->user()),
                'message' => 'DeviantArt session refreshed.',
            ]);
        } catch (DeviantArtOAuthException $exception) {
            $statusCode = $exception->requiresReconnect ? 409 : 422;

            return response()->json([
                'deviantart' => $deviantArt->statusForUser($request->user()),
                'message' => $exception->getMessage(),
            ], $statusCode);
        }
    }

    public function deviantArtDisconnect(Request $request, DeviantArtOAuthService $deviantArt): JsonResponse
    {
        $deviantArt->disconnect($request->user());

        return response()->json([
            'deviantart' => $deviantArt->statusForUser($request->user()),
            'message' => 'DeviantArt disconnected.',
        ]);
    }

    private function redirectToSettings(string $provider, string $notice, ?string $reason = null): RedirectResponse
    {
        $query = [
            "{$provider}_notice" => $notice,
        ];

        if ($reason !== null && trim($reason) !== '') {
            $query["{$provider}_reason"] = Str::of($reason)->limit(120)->toString();
        }

        return redirect()->to('/settings?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }
}
