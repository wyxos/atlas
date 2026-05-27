<?php

use App\Models\File;
use App\Models\User;
use App\Services\FilePreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('browse services endpoint returns civitai schema with expected field mappings', function () {
    $user = User::factory()->create();
    config([
        'services.deviantart.client_id' => 'client-id',
        'services.deviantart.client_secret' => 'client-secret',
        'services.deviantart.redirect_uri' => 'https://atlas.test/auth/deviantart/callback',
    ]);

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
    expect($local['defaults']['reaction'])->toBe(['love', 'like', 'funny']);
    expect($local['defaults']['downloaded'])->toBe('any');
    expect($local['defaults']['imported'])->toBe('any');
    expect($local['defaults']['not_found'])->toBe('no');
    expect($local['defaults']['blacklisted'])->toBe('any');
    expect($local['defaults']['auto_blacklisted'])->toBe('any');
    expect($local['defaults']['sort'])->toBe('stored_at');
    expect($local['defaults'])->toHaveKey('seed');
    expect($local['defaults'])->toHaveKey('max_previewed_count');
    expect($local['defaults'])->toHaveKey('min_previewed_count');
    expect($local['defaults']['file_type'])->toBe(['all']);

    $localFields = $local['schema']['fields'] ?? null;
    expect($localFields)->toBeArray();
    expect(array_column($localFields, 'uiKey'))->toBe([
        'page',
        'limit',
        'source',
        'file_type',
        'reaction_mode',
        'reaction',
        'downloaded',
        'not_found',
        'blacklisted',
        'auto_blacklisted',
        'sort',
        'seed',
    ]);

    $localLimit = collect($localFields)->firstWhere('uiKey', 'limit');
    expect($localLimit)->not->toBeNull();
    expect($localLimit['max'])->toBe(250);
    expect($localLimit['options'])->toBe([
        ['label' => '20', 'value' => 20],
        ['label' => '40', 'value' => 40],
        ['label' => '60', 'value' => 60],
        ['label' => '80', 'value' => 80],
        ['label' => '100', 'value' => 100],
        ['label' => '200', 'value' => 200],
        ['label' => '250', 'value' => 250],
    ]);

    $localSort = collect($localFields)->firstWhere('uiKey', 'sort');
    expect($localSort['options'])
        ->toContain(['label' => 'Created At (Newest)', 'value' => 'created_at'])
        ->toContain(['label' => 'Created At (Oldest)', 'value' => 'created_at_asc'])
        ->toContain(['label' => 'Updated At (Newest)', 'value' => 'updated_at'])
        ->toContain(['label' => 'Updated At (Oldest)', 'value' => 'updated_at_asc']);

    $localReactionMode = collect($localFields)->firstWhere('uiKey', 'reaction_mode');
    expect($localReactionMode['type'])->toBe('radio');

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

    $deviantArt = collect($services)->firstWhere('key', 'deviantart-images');
    expect($deviantArt)->not->toBeNull();
    expect($deviantArt['source'])->toBe('deviantart.com');
    expect($deviantArt['defaults']['q'])->toBe('');
    expect($deviantArt['status'])->toMatchArray([
        'state' => 'disconnected',
        'label' => 'Disconnected',
        'message' => 'Connect DeviantArt in Settings.',
    ]);

    $deviantArtFields = $deviantArt['schema']['fields'] ?? null;
    expect($deviantArtFields)->toBeArray();
    expect(array_column($deviantArtFields, 'uiKey'))->toBe([
        'page',
        'limit',
        'q',
        'tag',
        'username',
        'folderId',
        'nsfw',
    ]);

    $deviantArtPage = collect($deviantArtFields)->firstWhere('uiKey', 'page');
    expect($deviantArtPage['serviceKey'])->toBe('offset');
    expect($deviantArtPage['type'])->toBe('hidden');

    $deviantArtNsfw = collect($deviantArtFields)->firstWhere('uiKey', 'nsfw');
    expect($deviantArtNsfw['serviceKey'])->toBe('mature_content');
    expect($deviantArtNsfw['label'])->toBe('Mature Content');

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

test('browse services endpoint disables caching', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/browse/services');

    $response->assertOk();
    $response->assertHeader('Pragma', 'no-cache');

    $cacheControl = (string) $response->headers->get('Cache-Control', '');
    expect($cacheControl)->toContain('no-store');
    expect($cacheControl)->toContain('no-cache');
    expect($cacheControl)->toContain('must-revalidate');
    expect($cacheControl)->toContain('max-age=0');
});

test('browse services endpoint includes database sources in library source options', function () {
    $user = User::factory()->create();

    File::factory()->create(['source' => 'Spotify']);
    File::factory()->create(['source' => 'Bandcamp']);
    File::factory()->create(['source' => 'Spotify']);
    File::factory()->create(['source' => 'local']);
    File::factory()->create([
        'source' => 'FeedRemovedOnly',
        'blacklisted_at' => now(),
        'previewed_count' => FilePreviewService::FEED_REMOVED_PREVIEW_COUNT,
    ]);
    File::factory()->create([
        'source' => 'PreviewThresholdOnly',
        'previewed_count' => FilePreviewService::FEED_REMOVED_PREVIEW_COUNT,
    ]);
    File::factory()->create([
        'source' => 'MissingOnly',
        'not_found' => true,
    ]);
    File::factory()->create([
        'source' => 'BlacklistedStillInFeed',
        'blacklisted_at' => now(),
        'previewed_count' => FilePreviewService::FEED_REMOVED_PREVIEW_COUNT - 1,
    ]);

    $response = $this->actingAs($user)->getJson('/api/browse/services');

    $response->assertOk();

    $sourceField = collect($response->json('local.schema.fields'))->firstWhere('uiKey', 'source');

    expect($sourceField)->not->toBeNull()
        ->and($sourceField['options'])->toContain(['label' => 'All', 'value' => 'all'])
        ->and($sourceField['options'])->toContain(['label' => 'Spotify', 'value' => 'Spotify'])
        ->and($sourceField['options'])->toContain(['label' => 'Bandcamp', 'value' => 'Bandcamp'])
        ->and($sourceField['options'])->toContain(['label' => 'Library', 'value' => 'local'])
        ->and($sourceField['options'])->toContain(['label' => 'BlacklistedStillInFeed', 'value' => 'BlacklistedStillInFeed'])
        ->and($sourceField['options'])->not->toContain(['label' => 'FeedRemovedOnly', 'value' => 'FeedRemovedOnly'])
        ->and($sourceField['options'])->not->toContain(['label' => 'PreviewThresholdOnly', 'value' => 'PreviewThresholdOnly'])
        ->and($sourceField['options'])->not->toContain(['label' => 'MissingOnly', 'value' => 'MissingOnly'])
        ->and($sourceField['options'])->not->toContain(['label' => 'Local', 'value' => 'Local']);

    $sourcesResponse = $this->actingAs($user)->getJson('/api/browse/sources');

    $sourcesResponse->assertOk();
    expect($sourcesResponse->json('sources'))->toContain('Spotify');
});
