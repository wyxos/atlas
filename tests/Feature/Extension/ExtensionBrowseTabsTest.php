<?php

use App\Models\Tab;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function setExtensionBrowseTabsApiKey(string $value, ?int $userId = null): void
{
    DB::table('settings')->updateOrInsert([
        'key' => 'extension.api_key_hash',
        'machine' => '',
    ], [
        'value' => hash('sha256', $value),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    if ($userId !== null) {
        DB::table('settings')->updateOrInsert([
            'key' => 'extension.api_key_user_id',
            'machine' => '',
        ], [
            'value' => (string) $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

test('extension civitai browse tab endpoint requires a valid api key', function () {
    setExtensionBrowseTabsApiKey('valid-api-key');

    $this->postJson('/api/extension/browse-tabs/civitai-model', [
        'model_id' => 9303101,
    ])->assertUnauthorized();
});

it('creates and activates a civitai browse tab for the requested model filter', function (?int $modelVersionId) {
    $user = User::factory()->create();
    setExtensionBrowseTabsApiKey('valid-api-key', $user->id);

    $existingActiveTab = Tab::factory()->create([
        'user_id' => $user->id,
        'label' => 'Existing Active',
        'position' => 0,
        'is_active' => true,
    ]);
    Tab::factory()->create([
        'user_id' => $user->id,
        'label' => 'Existing Inactive',
        'position' => 1,
        'is_active' => false,
    ]);

    $payload = [
        'model_id' => 9303101,
    ];

    if ($modelVersionId !== null) {
        $payload['model_version_id'] = $modelVersionId;
    }

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/browse-tabs/civitai-model', $payload);

    $response->assertSuccessful();
    $response->assertJsonPath('tab.label', $modelVersionId === null
        ? 'CivitAI Images: Model 9303101 - 1'
        : "CivitAI Images: Model 9303101 @ {$modelVersionId} - 1");
    $response->assertJsonPath('tab.params.feed', 'online');
    $response->assertJsonPath('tab.params.service', 'civit-ai-images');
    $response->assertJsonPath('tab.params.modelId', 9303101);
    $response->assertJsonPath('browse_url', url('/browse'));

    $createdTab = Tab::query()
        ->where('user_id', $user->id)
        ->where('label', $modelVersionId === null
            ? 'CivitAI Images: Model 9303101 - 1'
            : "CivitAI Images: Model 9303101 @ {$modelVersionId} - 1")
        ->first();

    expect($createdTab)->not->toBeNull();
    expect($createdTab?->position)->toBe(2);
    expect($createdTab?->is_active)->toBeTrue();
    expect($existingActiveTab->fresh()?->is_active)->toBeFalse();
    expect($createdTab?->params['modelId'] ?? null)->toBe(9303101);

    if ($modelVersionId === null) {
        expect(array_key_exists('modelVersionId', $createdTab?->params ?? []))->toBeFalse();

        return;
    }

    expect($createdTab?->params['modelVersionId'] ?? null)->toBe($modelVersionId);
})->with([
    'model only' => [null],
    'model and version' => [9404101],
]);

it('creates and activates a civitai browse tab for the requested username filter', function () {
    $user = User::factory()->create();
    setExtensionBrowseTabsApiKey('valid-api-key', $user->id);

    $existingActiveTab = Tab::factory()->create([
        'user_id' => $user->id,
        'label' => 'Existing Active',
        'position' => 0,
        'is_active' => true,
    ]);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/browse-tabs/civitai-user', [
        'username' => ' forsunlee404 ',
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('tab.label', 'CivitAI Images: User forsunlee404 - 1');
    $response->assertJsonPath('tab.params.feed', 'online');
    $response->assertJsonPath('tab.params.service', 'civit-ai-images');
    $response->assertJsonPath('tab.params.username', 'forsunlee404');
    $response->assertJsonPath('browse_url', url('/browse'));

    $createdTab = Tab::query()
        ->where('user_id', $user->id)
        ->where('label', 'CivitAI Images: User forsunlee404 - 1')
        ->first();

    expect($createdTab)->not->toBeNull();
    expect($createdTab?->position)->toBe(1);
    expect($createdTab?->is_active)->toBeTrue();
    expect($existingActiveTab->fresh()?->is_active)->toBeFalse();
    expect($createdTab?->params['username'] ?? null)->toBe('forsunlee404');
});
