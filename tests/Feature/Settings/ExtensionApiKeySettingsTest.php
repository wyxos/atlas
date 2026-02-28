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
});

test('settings services includes extension api key status', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/settings/services');

    $response->assertSuccessful();
    $response->assertJsonPath('extension.api_key_configured', false);
    $response->assertJsonPath('extension.default_domain', rtrim((string) config('app.url', 'https://atlas.test'), '/'));
});

test('extension ping validates api key', function () {
    DB::table('settings')->insert([
        'key' => 'extension.api_key_hash',
        'machine' => '',
        'value' => hash('sha256', 'secret-key-123'),
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
