<?php

use App\Models\DeviantArtToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.deviantart.client_id' => 'deviantart-client-id',
        'services.deviantart.client_secret' => 'deviantart-client-secret',
        'services.deviantart.redirect_uri' => 'http://localhost/auth/deviantart/callback',
        'services.deviantart.scopes' => 'browse user',
    ]);
});

test('authenticated user can fetch deviantart service status', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/settings/services');

    $response->assertSuccessful();
    $response->assertJsonPath('deviantart.key', 'deviantart');
    $response->assertJsonPath('deviantart.connected', false);
    $response->assertJsonPath('deviantart.configured', true);
});

test('deviantart redirect endpoint starts oauth flow and stores state in session', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/auth/deviantart/redirect');

    $location = (string) $response->headers->get('Location');

    $response->assertStatus(302);
    expect($location)->toContain('https://www.deviantart.com/oauth2/authorize');
    expect($location)->toContain('client_id=deviantart-client-id');
    expect($location)->toContain('scope=basic%20browse%20user');
    expect($location)->toContain('code_challenge=');
    expect($location)->toContain('code_challenge_method=S256');
    expect($location)->toContain('state=');

    $response->assertSessionHas('deviantart.oauth.state');
    $response->assertSessionHas('deviantart.oauth.code_verifier');
    $response->assertSessionHas('deviantart.oauth.user_id', $user->id);
});

test('deviantart callback exchanges code and persists token', function () {
    $user = User::factory()->create();
    $state = 'deviantart-test-state';
    $codeVerifier = 'deviantart-code-verifier';

    Http::fake([
        'https://www.deviantart.com/oauth2/token' => Http::response([
            'access_token' => 'access-token-from-deviantart',
            'refresh_token' => 'refresh-token-from-deviantart',
            'scope' => 'browse user',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ]),
        'https://www.deviantart.com/api/v1/oauth2/user/whoami' => Http::response([
            'userid' => 'deviant-user-1',
            'username' => 'atlas-tester',
            'usericon' => 'https://a.deviantart.net/avatar.jpg',
        ]),
    ]);

    $response = $this
        ->actingAs($user)
        ->withSession([
            'deviantart.oauth.state' => $state,
            'deviantart.oauth.code_verifier' => $codeVerifier,
            'deviantart.oauth.user_id' => $user->id,
            'deviantart.oauth.started_at' => now()->timestamp,
        ])
        ->get('/auth/deviantart/callback?code=authorization-code&state='.$state);

    $response->assertRedirect('/settings?deviantart_notice=connected');

    $token = DeviantArtToken::query()->where('user_id', $user->id)->first();
    expect($token)->not->toBeNull();
    expect($token->access_token)->toBe('access-token-from-deviantart');
    expect($token->refresh_token)->toBe('refresh-token-from-deviantart');
    expect($token->scope)->toBe('browse user');
    expect($token->expires_at)->not->toBeNull();
    expect($token->account_userid)->toBe('deviant-user-1');
    expect($token->account_username)->toBe('atlas-tester');
});

test('deviantart refresh endpoint refreshes token and returns updated status', function () {
    $user = User::factory()->create();

    $token = DeviantArtToken::query()->create([
        'user_id' => $user->id,
        'access_token' => 'old-access-token',
        'refresh_token' => 'old-refresh-token',
        'scope' => 'browse user',
        'expires_at' => now()->subMinute(),
    ]);

    Http::fake([
        'https://www.deviantart.com/oauth2/token' => Http::response([
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'scope' => 'browse user',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ]),
        'https://www.deviantart.com/api/v1/oauth2/placebo' => Http::response([
            'status' => 'success',
        ]),
        'https://www.deviantart.com/api/v1/oauth2/user/whoami' => Http::response([
            'userid' => 'deviant-user-1',
            'username' => 'atlas-tester',
            'usericon' => 'https://a.deviantart.net/avatar.jpg',
        ]),
    ]);

    $response = $this->actingAs($user)->postJson('/api/settings/services/deviantart/refresh');

    $response->assertSuccessful();
    $response->assertJsonPath('deviantart.connected', true);
    $response->assertJsonPath('deviantart.session_valid', true);
    $response->assertJsonPath('deviantart.account.username', 'atlas-tester');
    $response->assertJsonPath('message', 'DeviantArt session refreshed.');

    $token->refresh();
    expect($token->access_token)->toBe('new-access-token');
    expect($token->refresh_token)->toBe('new-refresh-token');
});

test('deviantart disconnect endpoint removes deviantart token', function () {
    $user = User::factory()->create();
    DeviantArtToken::query()->create([
        'user_id' => $user->id,
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'scope' => 'browse user',
        'expires_at' => now()->addHour(),
    ]);

    $response = $this->actingAs($user)->deleteJson('/api/settings/services/deviantart');

    $response->assertSuccessful();
    $response->assertJsonPath('deviantart.connected', false);
    $response->assertJsonPath('message', 'DeviantArt disconnected.');
    expect(DeviantArtToken::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('deviantart status handles unreadable encrypted token and marks reconnect required', function () {
    $user = User::factory()->create();

    DB::table('deviant_art_tokens')->insert([
        'user_id' => $user->id,
        'access_token' => 'not-a-valid-encrypted-payload',
        'refresh_token' => 'not-a-valid-encrypted-payload',
        'scope' => 'browse user',
        'expires_at' => now()->addHour(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)->getJson('/api/settings/services');

    $response->assertSuccessful();
    $response->assertJsonPath('deviantart.connected', false);
    $response->assertJsonPath('deviantart.needs_reconnect', true);
    $response->assertJsonPath('deviantart.session_valid', false);
    $response->assertJsonPath('deviantart.can_refresh', false);
    expect(DeviantArtToken::query()->where('user_id', $user->id)->exists())->toBeFalse();
});
