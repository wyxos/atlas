<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('guest cannot save extension api key', function () {
    $response = $this->postJson('/api/settings/extension', [
        'api_key' => 'secret-key-123',
    ]);

    $response->assertUnauthorized();
});

test('guest cannot generate extension api key', function () {
    $response = $this->postJson('/api/settings/extension/generate');

    $response->assertUnauthorized();
});

test('authenticated user can save extension api key hash', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/settings/extension', [
        'api_key' => 'secret-key-123',
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('api_key_configured', true);

    $stored = DB::table('settings')
        ->where('key', 'extension.api_key_hash')
        ->where('machine', '')
        ->value('value');

    expect($stored)->toBe(hash('sha256', 'secret-key-123'));

    $storedUserId = DB::table('settings')
        ->where('key', 'extension.api_key_user_id')
        ->where('machine', '')
        ->value('value');

    expect($storedUserId)->toBe((string) $user->id);
});

test('authenticated user can generate and save extension api key hash', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/settings/extension/generate');

    $response->assertSuccessful();
    $response->assertJsonPath('api_key_configured', true);
    $generated = (string) $response->json('api_key');
    expect($generated)->not->toBe('');
    expect(str_starts_with($generated, 'atlas_'))->toBeTrue();

    $stored = DB::table('settings')
        ->where('key', 'extension.api_key_hash')
        ->where('machine', '')
        ->value('value');

    expect($stored)->toBe(hash('sha256', $generated));

    $storedUserId = DB::table('settings')
        ->where('key', 'extension.api_key_user_id')
        ->where('machine', '')
        ->value('value');

    expect($storedUserId)->toBe((string) $user->id);
});

test('settings services includes extension api key status', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/settings/services');

    $response->assertSuccessful();
    $response->assertJsonPath('extension.api_key_configured', false);
    $response->assertJsonPath('extension.default_domain', rtrim((string) config('app.url', 'https://atlas.test'), '/'));
});

test('extension ping validates api key', function () {
    $user = User::factory()->create();

    DB::table('settings')->insert([
        'key' => 'extension.api_key_hash',
        'machine' => '',
        'value' => hash('sha256', 'secret-key-123'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('settings')->insert([
        'key' => 'extension.api_key_user_id',
        'machine' => '',
        'value' => (string) $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $unauthorized = $this->withHeaders([
        'X-Atlas-Api-Key' => 'wrong-key',
    ])->getJson('/api/extension/ping');

    $unauthorized->assertUnauthorized();

    $authorized = $this->withHeaders([
        'X-Atlas-Api-Key' => 'secret-key-123',
    ])->getJson('/api/extension/ping');

    $authorized->assertSuccessful();
    $authorized->assertJsonPath('ok', true);
});
