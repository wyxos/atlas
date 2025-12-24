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

    // Create files
    $file1 = File::factory()->create([
        'downloaded_at' => now()->subDay(),
    ]);
    $file2 = File::factory()->create([
        'downloaded_at' => now()->subHours(12),
    ]);
    $file3 = File::factory()->create([
        'downloaded_at' => now()->subHours(6),
    ]);

    $result = $this->service->fetch(['page' => 1, 'limit' => 20]);

    expect($result)->toHaveKey('files');
    expect($result)->toHaveKey('metadata');
    expect($result['metadata'])->toHaveKey('nextCursor');
    expect($result['files'])->toHaveCount(3);
    expect($result['files'][0]->id)->toBe($file3->id); // More recent first
    expect($result['files'][1]->id)->toBe($file2->id);
    expect($result['files'][2]->id)->toBe($file1->id);
});

test('fetch filters by source when provided', function () {
    $file1 = File::factory()->create([
        'source' => 'CivitAI',
    ]);
    $file2 = File::factory()->create([
        'source' => 'Wallhaven',
    ]);

    $result = $this->service->fetch(['page' => 1, 'limit' => 20, 'source' => 'CivitAI']);

    expect($result['files'])->toHaveCount(1);
    expect($result['files'][0]->id)->toBe($file1->id);
});

test('fetch returns all sources when source is all', function () {
    $file1 = File::factory()->create([
        'source' => 'CivitAI',
    ]);
    $file2 = File::factory()->create([
        'source' => 'Wallhaven',
    ]);

    $result = $this->service->fetch(['page' => 1, 'limit' => 20, 'source' => 'all']);

    expect($result['files'])->toHaveCount(2);
});

test('fetch handles pagination correctly', function () {
    File::factory()->count(25)->create();

    $page1 = $this->service->fetch(['page' => 1, 'limit' => 10]);
    $page2 = $this->service->fetch(['page' => 2, 'limit' => 10]);
    $page3 = $this->service->fetch(['page' => 3, 'limit' => 10]);

    expect($page1['files'])->toHaveCount(10);
    expect($page2['files'])->toHaveCount(10);
    expect($page3['files'])->toHaveCount(5);
    expect($page1['metadata']['nextCursor'])->toBe(2);
    expect($page2['metadata']['nextCursor'])->toBe(3);
    expect($page3['metadata']['nextCursor'])->toBeNull();
});

test('transform returns file models directly', function () {
    $file = File::factory()->create([
        'url' => 'https://example.com/image.jpg',
        'thumbnail_url' => 'https://example.com/thumb.jpg',
        'source' => 'CivitAI',
    ]);

    $fetchResult = $this->service->fetch(['page' => 1, 'limit' => 20]);
    $transformResult = $this->service->transform($fetchResult);

    expect($transformResult)->toHaveKey('files');
    expect($transformResult)->toHaveKey('filter');
    expect($transformResult['files'])->toBeArray();
    expect($transformResult['files'][0])->toBeInstanceOf(File::class);
    expect($transformResult['files'][0]->source)->toBe('CivitAI');
});

test('defaultParams returns correct defaults', function () {
    $defaults = $this->service->defaultParams();

    expect($defaults)->toHaveKey('limit');
    expect($defaults)->toHaveKey('source');
    expect($defaults['limit'])->toBe(20);
    expect($defaults['source'])->toBe('all');
});
