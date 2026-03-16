<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function setExtensionBroadcastApiKey(string $value, int $userId): void
{
    DB::table('settings')->updateOrInsert([
        'key' => 'extension.api_key_hash',
        'machine' => '',
    ], [
        'value' => hash('sha256', $value),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('settings')->updateOrInsert([
        'key' => 'extension.api_key_user_id',
        'machine' => '',
    ], [
        'value' => (string) $userId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

beforeEach(function () {
    config()->set('broadcasting.connections.reverb.key', 'test-reverb-key');
    config()->set('broadcasting.connections.reverb.secret', 'test-reverb-secret');
    config()->set('broadcasting.connections.reverb.app_id', 'test-reverb-app');
});

test('extension broadcast auth requires a valid extension api key', function () {
    $user = User::factory()->create();
    setExtensionBroadcastApiKey('valid-key', $user->id);

    $response = $this->postJson('/api/extension/broadcasting/auth', [
        'socket_id' => '123.456',
        'channel_name' => 'private-extension-downloads.'.hash('sha256', 'valid-key'),
    ]);

    $response->assertUnauthorized();
});

test('extension broadcast auth returns a private channel signature for the matching extension channel', function () {
    $user = User::factory()->create();
    setExtensionBroadcastApiKey('valid-key', $user->id);

    $channelName = 'private-extension-downloads.'.hash('sha256', 'valid-key');
    $socketId = '123.456';

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-key',
    ])->postJson('/api/extension/broadcasting/auth', [
        'socket_id' => $socketId,
        'channel_name' => $channelName,
    ]);

    $response->assertOk();
    $response->assertJson([
        'auth' => 'test-reverb-key:'.hash_hmac('sha256', $socketId.':'.$channelName, 'test-reverb-secret'),
    ]);
});

test('extension broadcast auth rejects other extension channels', function () {
    $user = User::factory()->create();
    setExtensionBroadcastApiKey('valid-key', $user->id);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-key',
    ])->postJson('/api/extension/broadcasting/auth', [
        'socket_id' => '123.456',
        'channel_name' => 'private-extension-downloads.'.hash('sha256', 'other-key'),
    ]);

    $response->assertForbidden();
});
