<?php

use App\Models\File;
use App\Models\User;
use App\Services\LocalService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new LocalService;
});

test('returns correct key, source, and label', function () {
    expect(LocalService::key())->toBe('local');
    expect(LocalService::source())->toBe('Local');
    expect(LocalService::label())->toBe('Local Files');
});

test('fetch returns paginated local files', function () {
    $user = User::factory()->create();

    // Create files that should be included
    $file1 = File::factory()->create([
        'downloaded' => true,
        'downloaded_at' => now()->subDay(),
        'blacklisted_at' => null,
        'auto_disliked' => false,
    ]);
    $file2 = File::factory()->create([
        'downloaded' => true,
        'downloaded_at' => now()->subHours(12),
        'blacklisted_at' => null,
        'auto_disliked' => false,
    ]);

    // Create files that should be excluded
    File::factory()->create(['downloaded' => false]);
    File::factory()->create(['blacklisted_at' => now()]);
    File::factory()->create(['auto_disliked' => true]);

    $result = $this->service->fetch(['page' => 1, 'limit' => 20]);

    expect($result)->toHaveKey('items');
    expect($result)->toHaveKey('metadata');
    expect($result['metadata'])->toHaveKey('nextCursor');
    expect($result['items'])->toHaveCount(2);
    expect($result['items'][0]['id'])->toBe($file2->id); // More recent first
    expect($result['items'][1]['id'])->toBe($file1->id);
});

test('fetch filters by source when provided', function () {
    $file1 = File::factory()->create([
        'downloaded' => true,
        'blacklisted_at' => null,
        'auto_disliked' => false,
        'source' => 'CivitAI',
    ]);
    $file2 = File::factory()->create([
        'downloaded' => true,
        'blacklisted_at' => null,
        'auto_disliked' => false,
        'source' => 'Wallhaven',
    ]);

    $result = $this->service->fetch(['page' => 1, 'limit' => 20, 'source' => 'CivitAI']);

    expect($result['items'])->toHaveCount(1);
    expect($result['items'][0]['id'])->toBe($file1->id);
});

test('fetch returns all sources when source is all', function () {
    $file1 = File::factory()->create([
        'downloaded' => true,
        'blacklisted_at' => null,
        'auto_disliked' => false,
        'source' => 'CivitAI',
    ]);
    $file2 = File::factory()->create([
        'downloaded' => true,
        'blacklisted_at' => null,
        'auto_disliked' => false,
        'source' => 'Wallhaven',
    ]);

    $result = $this->service->fetch(['page' => 1, 'limit' => 20, 'source' => 'all']);

    expect($result['items'])->toHaveCount(2);
});

test('fetch handles pagination correctly', function () {
    File::factory()->count(25)->create([
        'downloaded' => true,
        'blacklisted_at' => null,
        'auto_disliked' => false,
    ]);

    $page1 = $this->service->fetch(['page' => 1, 'limit' => 10]);
    $page2 = $this->service->fetch(['page' => 2, 'limit' => 10]);
    $page3 = $this->service->fetch(['page' => 3, 'limit' => 10]);

    expect($page1['items'])->toHaveCount(10);
    expect($page2['items'])->toHaveCount(10);
    expect($page3['items'])->toHaveCount(5);
    expect($page1['metadata']['nextCursor'])->toBe(2);
    expect($page2['metadata']['nextCursor'])->toBe(3);
    expect($page3['metadata']['nextCursor'])->toBeNull();
});

test('fetch excludes blacklisted files', function () {
    File::factory()->create([
        'downloaded' => true,
        'blacklisted_at' => now(),
        'auto_disliked' => false,
    ]);
    $file2 = File::factory()->create([
        'downloaded' => true,
        'blacklisted_at' => null,
        'auto_disliked' => false,
    ]);

    $result = $this->service->fetch(['page' => 1, 'limit' => 20]);

    expect($result['items'])->toHaveCount(1);
    expect($result['items'][0]['id'])->toBe($file2->id);
});

test('fetch excludes auto-disliked files', function () {
    File::factory()->create([
        'downloaded' => true,
        'blacklisted_at' => null,
        'auto_disliked' => true,
    ]);
    $file2 = File::factory()->create([
        'downloaded' => true,
        'blacklisted_at' => null,
        'auto_disliked' => false,
    ]);

    $result = $this->service->fetch(['page' => 1, 'limit' => 20]);

    expect($result['items'])->toHaveCount(1);
    expect($result['items'][0]['id'])->toBe($file2->id);
});

test('fetch only includes downloaded files', function () {
    File::factory()->create([
        'downloaded' => false,
        'blacklisted_at' => null,
        'auto_disliked' => false,
    ]);
    $file2 = File::factory()->create([
        'downloaded' => true,
        'blacklisted_at' => null,
        'auto_disliked' => false,
    ]);

    $result = $this->service->fetch(['page' => 1, 'limit' => 20]);

    expect($result['items'])->toHaveCount(1);
    expect($result['items'][0]['id'])->toBe($file2->id);
});

test('transform converts file models to expected format', function () {
    $file = File::factory()->create([
        'downloaded' => true,
        'blacklisted_at' => null,
        'auto_disliked' => false,
        'url' => 'https://example.com/image.jpg',
        'thumbnail_url' => 'https://example.com/thumb.jpg',
        'source' => 'CivitAI',
    ]);

    $fetchResult = $this->service->fetch(['page' => 1, 'limit' => 20]);
    $transformResult = $this->service->transform($fetchResult);

    expect($transformResult)->toHaveKey('files');
    expect($transformResult)->toHaveKey('filter');
    expect($transformResult['files'])->toBeArray();
    expect($transformResult['files'][0])->toHaveKey('file');
    expect($transformResult['files'][0])->toHaveKey('metadata');
    expect($transformResult['files'][0]['file']['source'])->toBe('CivitAI');
});

test('defaultParams returns correct defaults', function () {
    $defaults = $this->service->defaultParams();

    expect($defaults)->toHaveKey('limit');
    expect($defaults)->toHaveKey('source');
    expect($defaults['limit'])->toBe(20);
    expect($defaults['source'])->toBe('all');
});
