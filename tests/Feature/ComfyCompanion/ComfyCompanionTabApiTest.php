<?php

use App\Models\Tab;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once dirname(__DIR__).'/Extension/ExtensionApiTestSupport.php';

uses(RefreshDatabase::class);

test('comfy companion tab endpoint creates an active CivitAI model browse tab', function () {
    $user = User::factory()->create();
    setExtensionApiKey('valid-api-key', $user->id);

    $existingActiveTab = Tab::factory()->create([
        'is_active' => true,
        'label' => 'Existing Active',
        'position' => 0,
        'user_id' => $user->id,
    ]);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/comfy-companion/tabs/civitai-model', [
        'model_id' => 9303101,
        'model_version_id' => 9404101,
        'nsfw' => true,
    ]);

    $response->assertOk();
    $response->assertJsonPath('tab.label', 'CivitAI Images: Model 9303101 @ 9404101 - 1');
    $response->assertJsonPath('tab.params.feed', 'online');
    $response->assertJsonPath('tab.params.service', 'civit-ai-images');
    $response->assertJsonPath('tab.params.modelId', 9303101);
    $response->assertJsonPath('tab.params.modelVersionId', 9404101);
    $response->assertJsonPath('tab.params.nsfw', true);
    $response->assertJsonPath('browse_url', url('/browse'));

    expect($existingActiveTab->fresh()?->is_active)->toBeFalse()
        ->and(Tab::query()->where('user_id', $user->id)->where('is_active', true)->count())->toBe(1);
});

test('comfy companion tab endpoint requires a valid extension api key', function () {
    setExtensionApiKey('valid-api-key');

    $this->postJson('/api/comfy-companion/tabs/civitai-model', [
        'model_id' => 9303101,
    ])->assertUnauthorized();
});

test('comfy companion tab endpoint is not exposed on the extension route prefix', function () {
    setExtensionApiKey('valid-api-key');

    $this->postJson('/api/extension/tabs/civitai-model', [
        'model_id' => 9303101,
    ])->assertStatus(405);
});
