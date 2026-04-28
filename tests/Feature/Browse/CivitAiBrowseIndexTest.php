<?php

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/BrowseIndexTestSupport.php';

uses(RefreshDatabase::class);

test('civitai browse recovers model id sent as modelVersionId and heals persisted tab params', function () {
    $user = User::factory()->create();
    $tab = \App\Models\Tab::factory()->for($user)->create([
        'params' => ['feed' => 'online', 'service' => 'civit-ai-images'],
    ]);

    Http::fake([
        'https://civitai.com/api/v1/images*' => Http::sequence()
            ->push([
                'items' => [],
                'metadata' => [],
            ], 200)
            ->push([
                'items' => [[
                    'id' => 777,
                    'url' => 'https://image.civitai.com/x/y/777.jpeg',
                    'width' => 640,
                    'height' => 480,
                ]],
                'metadata' => [
                    'nextCursor' => null,
                ],
            ], 200),
        'https://civitai.com/api/v1/model-versions/1692152' => Http::response([
            'error' => 'Not found',
        ], 404),
        'https://civitai.com/api/v1/models/1692152' => Http::response([
            'id' => 1692152,
        ], 200),
    ]);

    $response = $this->actingAs($user)->getJson("/api/browse?tab_id={$tab->id}&feed=online&service=civit-ai-images&page=1&limit=20&modelVersionId=1692152");

    $response->assertSuccessful();
    $response->assertJsonCount(1, 'items');

    $tab->refresh();

    expect($tab->params['modelId'] ?? null)->toBe(1692152);
    expect(array_key_exists('modelVersionId', $tab->params))->toBeFalse();
});

test('civitai browse keeps real modelVersionId filters when the version exists', function () {
    $user = User::factory()->create();
    $tab = \App\Models\Tab::factory()->for($user)->create([
        'params' => ['feed' => 'online', 'service' => 'civit-ai-images'],
    ]);

    Http::fake([
        'https://civitai.com/api/v1/images*' => Http::response([
            'items' => [],
            'metadata' => [],
        ], 200),
        'https://civitai.com/api/v1/model-versions/1692152' => Http::response([
            'id' => 1692152,
        ], 200),
    ]);

    $response = $this->actingAs($user)->getJson("/api/browse?tab_id={$tab->id}&feed=online&service=civit-ai-images&page=1&limit=20&modelVersionId=1692152");

    $response->assertSuccessful();
    $response->assertJsonCount(0, 'items');

    $tab->refresh();

    expect((string) ($tab->params['modelVersionId'] ?? ''))->toBe('1692152');
    expect(array_key_exists('modelId', $tab->params))->toBeFalse();
});

test('civitai browse surfaces prompts from nested model metadata responses', function () {
    $user = User::factory()->create();

    Http::fake([
        'https://civitai.com/api/v1/images*' => Http::response([
            'items' => [[
                'id' => 124538995,
                'url' => 'https://image.civitai.com/example/image.jpeg',
                'width' => 1024,
                'height' => 1024,
                'meta' => [
                    'id' => 124538995,
                    'meta' => [
                        'prompt' => 'nested civitai prompt',
                        'sampler' => 'Euler a',
                    ],
                ],
            ]],
            'metadata' => [
                'nextCursor' => null,
            ],
        ], 200),
    ]);

    $response = $this->actingAs($user)->getJson('/api/browse?service=civit-ai-images&modelId=257749');

    $response->assertSuccessful();
    expect($response->json('items.0.metadata.prompt'))->toBe('nested civitai prompt');

    $file = File::query()->with('metadata')->sole();

    expect($file->metadata)->not->toBeNull()
        ->and($file->metadata->payload['prompt'] ?? null)->toBe('nested civitai prompt');
});
