<?php

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

require_once dirname(__DIR__).'/Extension/ExtensionApiTestSupport.php';

uses(RefreshDatabase::class);

test('comfy companion browse feed reuses Atlas CivitAI browse and returns persisted media state', function () {
    $user = User::factory()->create();
    setExtensionApiKey('valid-api-key', $user->id);

    $candidateUrl = 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/77777777-7777-4777-8777-777777777777/original=true/9103022.jpeg';

    Http::fake([
        'https://civitai.com/api/v1/images*' => Http::response([
            'items' => [
                [
                    'id' => 9103022,
                    'url' => $candidateUrl,
                    'type' => 'image',
                    'nsfwLevel' => 2,
                    'width' => 768,
                    'height' => 1024,
                    'meta' => ['prompt' => 'visible candidate'],
                ],
            ],
            'metadata' => [
                'nextCursor' => 'next-cursor',
            ],
        ]),
    ]);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/comfy-companion/browse/civitai-model', [
        'model_id' => 9303001,
        'model_version_id' => 9404001,
        'limit' => 20,
        'cursor' => 'cursor-1',
        'nsfw' => true,
    ]);

    $response->assertOk();
    $response->assertJsonPath('ok', true);
    $response->assertJsonPath('items.0.media.id', 9103022);
    $response->assertJsonPath('items.0.media.width', 768);
    $response->assertJsonPath('items.0.media.height', 1024);
    $response->assertJsonPath('items.0.atlasStatus.exists', true);
    $response->assertJsonPath('items.0.atlasStatus.referrer_url', 'https://civitai.red/images/9103022');
    $response->assertJsonPath('metadata.nextCursor', 'next-cursor');

    $file = File::query()
        ->where('source', 'CivitAI')
        ->where('source_id', '9103022')
        ->sole();

    expect($response->json('items.0.atlasStatus.file_id'))->toBe($file->id);

    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://civitai.com/api/v1/images')
        && $request->data()['modelId'] === 9303001
        && $request->data()['modelVersionId'] === 9404001
        && $request->data()['cursor'] === 'cursor-1'
        && $request->data()['nsfw'] === true);
});

test('comfy companion browse feed requires a valid extension api key', function () {
    setExtensionApiKey('valid-api-key');

    $this->postJson('/api/comfy-companion/browse/civitai-model', [
        'model_id' => 9303001,
    ])->assertUnauthorized();
});

test('comfy companion browse feed is not exposed on the extension route prefix', function () {
    setExtensionApiKey('valid-api-key');

    $this->postJson('/api/extension/browse/civitai-model', [
        'model_id' => 9303001,
    ])->assertStatus(405);
});
