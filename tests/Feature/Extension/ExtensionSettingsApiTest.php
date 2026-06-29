<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/ExtensionApiTestSupport.php';

uses(RefreshDatabase::class);

test('extension settings require a valid extension api key', function () {
    setExtensionApiKey('valid-api-key');

    $this->getJson('/api/extension/settings')->assertUnauthorized();

    $this->putJson('/api/extension/settings', [
        'settings' => [
            'schemaVersion' => 1,
            'settings' => [
                'connection' => [],
            ],
        ],
    ])->assertUnauthorized();
});

test('extension settings are stored and loaded for the authenticated extension user', function () {
    $user = User::factory()->create();
    setExtensionApiKey('valid-api-key', $user->id);

    $settings = [
        'schemaVersion' => 1,
        'settings' => [
            'assetSourcePreferences' => [
                'domains' => ['reddit.com'],
                'version' => 2,
            ],
            'connection' => [
                'mode' => 'live',
                'profiles' => [
                    'live' => [
                        'apiKey' => '',
                        'domain' => 'https://atlas.example.test',
                        'status' => 'connected',
                    ],
                    'local' => [
                        'status' => 'idle',
                    ],
                ],
                'version' => 2,
            ],
        ],
    ];

    $putResponse = $this->withHeader('X-Atlas-Api-Key', 'valid-api-key')
        ->putJson('/api/extension/settings', [
            'settings' => $settings,
        ]);

    $putResponse->assertOk();
    $putResponse->assertJsonPath('settings.schemaVersion', 1);
    $putResponse->assertJsonPath('settings.settings.assetSourcePreferences.domains.0', 'reddit.com');
    expect($putResponse->json('settings.settings.connection.profiles.live.apiKey'))->toBe('');

    $stored = DB::table('settings')
        ->where('key', 'extension.client_settings.v1')
        ->where('machine', 'user:'.$user->id)
        ->first();

    expect($stored)->not->toBeNull();
    expect(json_decode((string) $stored->value, true))->toBe($settings);

    $getResponse = $this->withHeader('X-Atlas-Api-Key', 'valid-api-key')
        ->getJson('/api/extension/settings');

    $getResponse->assertOk();
    $getResponse->assertJsonPath('settings.settings.assetSourcePreferences.domains.0', 'reddit.com');
});

test('extension settings return null before the user has uploaded settings', function () {
    $user = User::factory()->create();
    setExtensionApiKey('valid-api-key', $user->id);

    $response = $this->withHeader('X-Atlas-Api-Key', 'valid-api-key')
        ->getJson('/api/extension/settings');

    $response->assertOk();
    $response->assertJsonPath('settings', null);
});

test('extension settings reject unsupported schema versions', function () {
    $user = User::factory()->create();
    setExtensionApiKey('valid-api-key', $user->id);

    $response = $this->withHeader('X-Atlas-Api-Key', 'valid-api-key')
        ->putJson('/api/extension/settings', [
            'settings' => [
                'schemaVersion' => 999,
                'settings' => [],
            ],
        ]);

    $response->assertUnprocessable();
});
