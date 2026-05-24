<?php

namespace App\Http\Controllers;

use App\Services\Spotify\SpotifyOAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SpotifyPlaybackTokenController extends Controller
{
    public function __invoke(Request $request, SpotifyOAuthService $spotify): JsonResponse
    {
        $accessToken = $spotify->getValidAccessToken($request->user());

        if ($accessToken === null || trim($accessToken) === '') {
            return response()->json([
                'message' => 'Spotify is not connected for this account.',
            ], 409);
        }

        return response()->json([
            'access_token' => $accessToken,
        ]);
    }
}
