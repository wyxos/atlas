<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('browse services endpoint returns civitai schema with expected field mappings', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/browse/services');

    $response->assertOk();

    $response->assertJsonStructure([
        'services',
        'local' => [
            'key',
            'label',
            'defaults',
            'schema' => ['fields'],
        ],
    ]);

    $services = $response->json('services');
    expect($services)->toBeArray();

    expect(collect($services)->contains(fn ($s) => ($s['key'] ?? null) === 'local'))->toBeFalse();

    $local = $response->json('local');
    expect($local)->toBeArray();
    expect($local['key'])->toBe('local');
    expect($local['defaults']['reaction_mode'])->toBe('any');
    expect($local['defaults']['reaction'])->toBe(['love', 'like', 'dislike', 'funny']);
    expect($local['defaults']['downloaded'])->toBe('any');
    expect($local['defaults']['blacklisted'])->toBe('any');
    expect($local['defaults']['blacklist_type'])->toBe('any');
    expect($local['defaults']['auto_disliked'])->toBe('any');
    expect($local['defaults']['sort'])->toBe('downloaded_at');
    expect($local['defaults'])->toHaveKey('seed');
    expect($local['defaults'])->toHaveKey('max_previewed_count');

    $localFields = $local['schema']['fields'] ?? null;
    expect($localFields)->toBeArray();
    expect(array_column($localFields, 'uiKey'))->toBe([
        'page',
        'limit',
        'source',
        'reaction_mode',
        'reaction',
        'downloaded',
        'blacklisted',
        'blacklist_type',
        'auto_disliked',
        'sort',
        'seed',
        'max_previewed_count',
    ]);

    $civit = collect($services)->firstWhere('key', 'civit-ai-images');
    expect($civit)->not->toBeNull();

    $schema = $civit['schema'] ?? null;
    expect($schema)->toBeArray();

    $fields = $schema['fields'] ?? null;
    expect($fields)->toBeArray();

    // Stable ordering + presence
    expect(array_column($fields, 'uiKey'))->toBe([
        'page',
        'limit',
        'postId',
        'modelId',
        'modelVersionId',
        'username',
        'nsfw',
        'type',
        'sort',
        'period',
    ]);

    $page = collect($fields)->firstWhere('uiKey', 'page');
    expect($page['serviceKey'])->toBe('cursor');
    expect($page['type'])->toBe('hidden');
    expect($page['label'])->toBe('Page');

    $modelId = collect($fields)->firstWhere('uiKey', 'modelId');
    expect($modelId['label'])->toBe('Model ID');
    expect($modelId['type'])->toBe('number');

    $nsfw = collect($fields)->firstWhere('uiKey', 'nsfw');
    expect($nsfw['label'])->toBe('NSFW');
    expect($nsfw['type'])->toBe('boolean');

    $type = collect($fields)->firstWhere('uiKey', 'type');
    expect($type['type'])->toBe('radio');
    expect($type['options'])->toBe([
        ['label' => 'All', 'value' => 'all'],
        ['label' => 'Image', 'value' => 'image'],
        ['label' => 'Video', 'value' => 'video'],
    ]);

    $wallhaven = collect($services)->firstWhere('key', 'wallhaven');
    expect($wallhaven)->not->toBeNull();

    $wallSchema = $wallhaven['schema'] ?? null;
    expect($wallSchema)->toBeArray();

    $wallFields = $wallSchema['fields'] ?? null;
    expect($wallFields)->toBeArray();

    // Stable ordering + presence
    expect(array_column($wallFields, 'uiKey'))->toBe([
        'page',
        'limit',
        'q',
        'categories',
        'nsfw',
        'sort',
        'order',
        'topRange',
        'atleast',
        'resolutions',
        'ratios',
        'seed',
    ]);

    $wallSort = collect($wallFields)->firstWhere('uiKey', 'sort');
    expect($wallSort['serviceKey'])->toBe('sorting');
    expect($wallSort['type'])->toBe('select');

    $wallNsfw = collect($wallFields)->firstWhere('uiKey', 'nsfw');
    expect($wallNsfw['label'])->toBe('Purity');
    expect($wallNsfw['type'])->toBe('radio');
});
