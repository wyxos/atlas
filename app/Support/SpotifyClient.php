<?php

namespace App\Support;

use App\Models\SpotifyToken;
use Illuminate\Support\Facades\Http;

class SpotifyClient
{
    private const TOKEN_URL = 'https://accounts.spotify.com/api/token';

    public function getAccessTokenForUser(int $userId): ?string
    {
        $record = SpotifyToken::where('user_id', $userId)->first();
        if (! $record) {
            return null;
        }

        if ($record->expires_at && now()->isBefore($record->expires_at->subSeconds(30))) {
            return (string) $record->access_token;
        }

        return $this->refreshForUser($userId) ? (string) SpotifyToken::where('user_id', $userId)->value('access_token') : null;
    }

    public function refreshForUser(int $userId): bool
    {
        $record = SpotifyToken::where('user_id', $userId)->first();
        if (! $record || ! $record->refresh_token) {
            return false;
        }

        $clientId = (string) (config('services.spotify.client_id') ?? '');
        if ($clientId === '') {
            return false;
        }

        $tokenResponse = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $record->refresh_token,
            'client_id' => $clientId,
        ]);
        if (! $tokenResponse->ok()) {
            return false;
        }

        $payload = (array) $tokenResponse->json();
        $accessToken = (string) ($payload['access_token'] ?? '');
        $expiresIn = (int) ($payload['expires_in'] ?? 3600);
        $newRefreshToken = (string) ($payload['refresh_token'] ?? '');

        if ($accessToken === '') {
            return false;
        }

        $record->access_token = $accessToken;
        if ($newRefreshToken !== '') {
            $record->refresh_token = $newRefreshToken;
        }
        $record->expires_at = now()->addSeconds($expiresIn - 30);
        $record->save();

        return true;
    }
}
