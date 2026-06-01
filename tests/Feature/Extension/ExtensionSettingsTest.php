<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function setExtensionSettingsApiKey(string $value, int $userId): void
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

test('extension settings require a valid extension api key', function () {
    $this->withHeaders([
        'X-Atlas-Api-Key' => 'wrong-key',
    ])->getJson('/api/extension/settings')
        ->assertUnauthorized();

    $this->withHeaders([
        'X-Atlas-Api-Key' => 'wrong-key',
    ])->postJson('/api/extension/settings', [
        'settings' => [
            'siteCustomizations' => [],
        ],
    ])->assertUnauthorized();
});

test('extension settings default to an empty domain-backed profile', function () {
    $user = User::factory()->create();
    setExtensionSettingsApiKey('valid-api-key', (int) $user->id);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->getJson('/api/extension/settings');

    $response->assertSuccessful();
    $response->assertJson([
        'settings' => [
            'version' => 1,
            'siteCustomizations' => [],
            'closeTabAfterQueueByDomain' => [],
            'reactAllItemsInPostByDomain' => [],
        ],
    ]);
});

test('extension settings are stored for the resolved extension user', function () {
    $user = User::factory()->create();
    setExtensionSettingsApiKey('valid-api-key', (int) $user->id);

    $payload = [
        'settings' => [
            'version' => 1,
            'siteCustomizations' => [
                [
                    'enabled' => false,
                    'domain' => 'https://Example.com/path',
                    'matchRules' => [' .*\\/gallery\\/.* ', ''],
                    'widget' => [
                        'minImageWidth' => 120,
                    ],
                    'referrerCleaner' => [
                        'stripQueryParams' => ['Tag', 'tag'],
                    ],
                    'mediaCleaner' => [
                        'stripQueryParams' => ['Width', 'width'],
                        'rewriteRules' => [
                            [
                                'pattern' => ' /foo/ ',
                                'replace' => 'bar',
                            ],
                            [
                                'pattern' => ' /foo/ ',
                                'replace' => 'bar',
                            ],
                        ],
                        'strategies' => ['civitaiCanonical', 'ignored'],
                    ],
                ],
            ],
            'closeTabAfterQueueByDomain' => [
                'https://Example.com/path' => 'completed',
                'legacy.example' => true,
                'invalid.example' => 'later',
            ],
            'reactAllItemsInPostByDomain' => [
                'https://Example.com/path' => true,
                'invalid.example' => 'yes',
            ],
        ],
    ];

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/settings', $payload);

    $response->assertSuccessful();
    $response->assertJsonPath('settings.siteCustomizations.0.domain', 'example.com');
    $response->assertJsonPath('settings.siteCustomizations.0.enabled', false);
    $response->assertJsonPath('settings.siteCustomizations.0.matchRules', ['.*\\/gallery\\/.*']);
    $response->assertJsonPath('settings.siteCustomizations.0.referrerCleaner.stripQueryParams', ['tag']);
    $response->assertJsonPath('settings.siteCustomizations.0.mediaCleaner.stripQueryParams', ['width']);
    $response->assertJsonPath('settings.siteCustomizations.0.mediaCleaner.rewriteRules', [
        [
            'pattern' => '/foo/',
            'replace' => 'bar',
        ],
    ]);
    $response->assertJsonPath('settings.siteCustomizations.0.mediaCleaner.strategies', ['civitaiCanonical']);
    $response->assertJsonPath('settings.closeTabAfterQueueByDomain', [
        'example.com' => 'completed',
        'legacy.example' => 'queued',
    ]);
    $response->assertJsonPath('settings.reactAllItemsInPostByDomain', [
        'example.com' => true,
    ]);

    $stored = DB::table('settings')
        ->where('key', 'extension.settings')
        ->where('machine', 'user:'.$user->id)
        ->value('value');

    expect($stored)->not->toBeNull();
    expect(json_decode((string) $stored, true))->toBe($response->json('settings'));

    $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->getJson('/api/extension/settings')
        ->assertSuccessful()
        ->assertJsonPath('settings', $response->json('settings'));
});
