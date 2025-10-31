<?php

use App\Models\SpotifyToken;
use App\Models\User;
use App\Support\SpotifyClient;

use function Pest\Laravel\mock;

it('returns unauthorized when Spotify is not connected', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/spotify/token')
        ->assertStatus(401)
        ->assertJson(['error' => 'Not connected']);
});

it('returns access token details when Spotify is connected', function (): void {
    $user = User::factory()->create();

    SpotifyToken::create([
        'user_id' => $user->id,
        'access_token' => 'old-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addMinute(),
        'scope' => 'streaming user-read-email',
    ]);

    mock(SpotifyClient::class, function ($mock) use ($user) {
        $mock->shouldReceive('getAccessTokenForUser')
            ->once()
            ->with($user->id)
            ->andReturn('new-access-token');
    });

    $this->actingAs($user)
        ->getJson('/spotify/token')
        ->assertOk()
        ->assertJson([
            'access_token' => 'new-access-token',
            'scope' => 'streaming user-read-email',
        ]);
});
