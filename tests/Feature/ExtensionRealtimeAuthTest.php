<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function configureExtensionRealtime(int $userId): void
{
    config()->set('downloads.extension_token', 'test-token');
    config()->set('downloads.extension_user_id', $userId);
    config()->set('broadcasting.connections.reverb.key', 'test-key');
    config()->set('broadcasting.connections.reverb.secret', 'test-secret');
    config()->set('broadcasting.connections.reverb.options.host', 'atlas.test');
    config()->set('broadcasting.connections.reverb.options.port', 443);
    config()->set('broadcasting.connections.reverb.options.scheme', 'https');
    config()->set('app.url', 'https://atlas.test');
}

it('returns realtime socket config for a valid extension token', function () {
    $user = User::factory()->create();
    configureExtensionRealtime($user->id);

    $response = $this
        ->withHeader('X-Atlas-Extension-Token', 'test-token')
        ->getJson('/api/extension/realtime');

    $response->assertOk();
    $response->assertJsonPath('key', 'test-key');
    $response->assertJsonPath('wsHost', 'atlas.test');
    $response->assertJsonPath('forceTLS', true);
    $response->assertJsonPath('authEndpoint', 'https://atlas.test/api/extension/broadcasting/auth');
    $response->assertJsonPath('channel', "private-extension-downloads.{$user->id}");
});

it('rejects realtime socket config requests with an invalid extension token', function () {
    $user = User::factory()->create();
    configureExtensionRealtime($user->id);

    $response = $this
        ->withHeader('X-Atlas-Extension-Token', 'invalid-token')
        ->getJson('/api/extension/realtime');

    $response->assertForbidden();
});

it('returns channel auth signature for the extension private channel', function () {
    $user = User::factory()->create();
    configureExtensionRealtime($user->id);

    $socketId = '1234.5678';
    $channel = "private-extension-downloads.{$user->id}";

    $response = $this
        ->withHeader('X-Atlas-Extension-Token', 'test-token')
        ->postJson('/api/extension/broadcasting/auth', [
            'socket_id' => $socketId,
            'channel_name' => $channel,
        ]);

    $response->assertOk();
    $expectedSignature = hash_hmac('sha256', "{$socketId}:{$channel}", 'test-secret');
    $response->assertJsonPath('auth', "test-key:{$expectedSignature}");
});

it('rejects channel auth requests for a different extension user channel', function () {
    $user = User::factory()->create();
    configureExtensionRealtime($user->id);

    $response = $this
        ->withHeader('X-Atlas-Extension-Token', 'test-token')
        ->postJson('/api/extension/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-extension-downloads.999999',
        ]);

    $response->assertForbidden();
});
