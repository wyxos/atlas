<?php

use App\Models\SpotifyToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.spotify.client_id' => 'spotify-client-id',
        'services.spotify.client_secret' => 'spotify-client-secret',
        'services.spotify.redirect_uri' => 'http://localhost/auth/spotify/callback',
        'services.spotify.scopes' => 'playlist-read-private streaming',
    ]);
});

test('guest cannot list settings services', function () {
    $response = $this->getJson('/api/settings/services');

    $response->assertUnauthorized();
});

test('authenticated user can fetch spotify service status', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/settings/services');

    $response->assertSuccessful();
    $response->assertJsonPath('spotify.key', 'spotify');
    $response->assertJsonPath('spotify.connected', false);
    $response->assertJsonPath('spotify.configured', true);
});

test('spotify redirect endpoint starts oauth flow and stores state in session', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/auth/spotify/redirect');

    $location = (string) $response->headers->get('Location');

    $response->assertStatus(302);
    expect($location)->toContain('https://accounts.spotify.com/authorize');
    expect($location)->toContain('client_id=spotify-client-id');
    expect($location)->toContain('code_challenge=');
    expect($location)->toContain('state=');

    $response->assertSessionHas('spotify.oauth.state');
    $response->assertSessionHas('spotify.oauth.code_verifier');
    $response->assertSessionHas('spotify.oauth.user_id', $user->id);
});

test('spotify callback exchanges code and persists token', function () {
    $user = User::factory()->create();
    $state = 'spotify-test-state';
    $codeVerifier = 'spotify-code-verifier';

    Http::fake([
        'https://accounts.spotify.com/api/token' => Http::response([
            'access_token' => 'access-token-from-spotify',
            'refresh_token' => 'refresh-token-from-spotify',
            'scope' => 'playlist-read-private streaming',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ]),
    ]);

    $response = $this
        ->actingAs($user)
        ->withSession([
            'spotify.oauth.state' => $state,
            'spotify.oauth.code_verifier' => $codeVerifier,
            'spotify.oauth.user_id' => $user->id,
            'spotify.oauth.started_at' => now()->timestamp,
        ])
        ->get('/auth/spotify/callback?code=authorization-code&state='.$state);

    $response->assertRedirect('/settings?spotify_notice=connected');

    $token = SpotifyToken::query()->where('user_id', $user->id)->first();
    expect($token)->not->toBeNull();
    expect($token->access_token)->toBe('access-token-from-spotify');
    expect($token->refresh_token)->toBe('refresh-token-from-spotify');
    expect($token->scope)->toBe('playlist-read-private streaming');
    expect($token->expires_at)->not->toBeNull();
});

test('spotify refresh endpoint refreshes token and returns updated status', function () {
    $user = User::factory()->create();

    $token = SpotifyToken::query()->create([
        'user_id' => $user->id,
        'access_token' => 'old-access-token',
        'refresh_token' => 'old-refresh-token',
        'scope' => 'playlist-read-private',
        'expires_at' => now()->subMinute(),
    ]);

    Http::fake([
        'https://accounts.spotify.com/api/token' => Http::response([
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'scope' => 'playlist-read-private streaming',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ]),
        'https://api.spotify.com/v1/me' => Http::response([
            'id' => 'spotify-user-1',
            'display_name' => 'Atlas Tester',
            'email' => 'tester@example.com',
            'product' => 'premium',
            'country' => 'US',
        ]),
    ]);

    $response = $this->actingAs($user)->postJson('/api/settings/services/spotify/refresh');

    $response->assertSuccessful();
    $response->assertJsonPath('spotify.connected', true);
    $response->assertJsonPath('spotify.session_valid', true);
    $response->assertJsonPath('spotify.account.id', 'spotify-user-1');
    $response->assertJsonPath('message', 'Spotify session refreshed.');

    $token->refresh();
    expect($token->access_token)->toBe('new-access-token');
    expect($token->refresh_token)->toBe('new-refresh-token');
});

test('spotify disconnect endpoint removes spotify token', function () {
    $user = User::factory()->create();
    SpotifyToken::query()->create([
        'user_id' => $user->id,
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'scope' => 'playlist-read-private',
        'expires_at' => now()->addHour(),
    ]);

    $response = $this->actingAs($user)->deleteJson('/api/settings/services/spotify');

    $response->assertSuccessful();
    $response->assertJsonPath('spotify.connected', false);
    $response->assertJsonPath('message', 'Spotify disconnected.');
    expect(SpotifyToken::query()->where('user_id', $user->id)->exists())->toBeFalse();
});
