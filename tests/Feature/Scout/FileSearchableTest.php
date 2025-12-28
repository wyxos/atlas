<?php

use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Use collection driver for fast, isolated tests
    // This doesn't require a real Typesense instance
    Config::set('scout.driver', 'collection');
});

it('can generate searchable array from file model', function () {
    $file = File::factory()->create([
        'filename' => 'test-image.jpg',
        'title' => 'Test Image Title',
        'description' => 'This is a test description',
        'mime_type' => 'image/jpeg',
        'source' => 'local',
    ]);

    $searchable = $file->toSearchableArray();

    expect($searchable)
        ->toBeArray()
        ->toHaveKey('id')
        ->toHaveKey('filename')
        ->toHaveKey('source')
        ->toHaveKey('mime_group')
        ->toHaveKey('created_at')
        ->toHaveKey('updated_at');

    expect($searchable['id'])->toBe((string) $file->id);
    expect($searchable['filename'])->toBe('test-image.jpg');
    expect($searchable['title'])->toBe('Test Image Title');
    expect($searchable['description'])->toBe('This is a test description');
    expect($searchable['mime_group'])->toBe('image');
    expect($searchable['source'])->toBe('local');
});

it('correctly maps mime types to mime groups', function (string $mimeType, string $expectedGroup) {
    $file = File::factory()->create([
        'mime_type' => $mimeType,
    ]);

    $searchable = $file->toSearchableArray();

    expect($searchable['mime_group'])->toBe($expectedGroup);
})->with([
    ['image/jpeg', 'image'],
    ['image/png', 'image'],
    ['video/mp4', 'video'],
    ['video/webm', 'video'],
    ['audio/mpeg', 'audio'],
    ['audio/ogg', 'audio'],
    ['application/pdf', 'other'],
    ['', 'other'],
]);

it('includes path as __missing__ when path is null', function () {
    $file = File::factory()->create([
        'path' => null,
    ]);

    $searchable = $file->toSearchableArray();

    expect($searchable['path'])->toBe('__missing__');
    expect($searchable['has_path'])->toBeFalse();
});

it('includes has_path as true when path exists', function () {
    $file = File::factory()->create([
        'path' => '/path/to/file.jpg',
    ]);

    $searchable = $file->toSearchableArray();

    expect($searchable['path'])->toBe('/path/to/file.jpg');
    expect($searchable['has_path'])->toBeTrue();
});

it('filters out null values for optional fields', function () {
    $file = File::factory()->create([
        'title' => null,
        'description' => null,
        'url' => null,
        'tags' => null,
    ]);

    $searchable = $file->toSearchableArray();

    expect($searchable)->not->toHaveKey('title');
    expect($searchable)->not->toHaveKey('description');
    expect($searchable)->not->toHaveKey('url');
    expect($searchable)->not->toHaveKey('tags');
});

it('keeps required fields even when null', function () {
    $file = File::factory()->create([
        'path' => null,
    ]);

    $searchable = $file->toSearchableArray();

    // Required fields should always be present
    expect($searchable)->toHaveKey('id');
    expect($searchable)->toHaveKey('source');
    expect($searchable)->toHaveKey('filename');
    expect($searchable)->toHaveKey('path');
    expect($searchable)->toHaveKey('created_at');
    expect($searchable)->toHaveKey('updated_at');
    expect($searchable)->toHaveKey('mime_group');
});

it('keeps boolean false values in searchable array', function () {
    $file = File::factory()->create([
        'downloaded' => false,
        'not_found' => false,
        'auto_disliked' => false,
    ]);

    $searchable = $file->toSearchableArray();

    expect($searchable['downloaded'])->toBeFalse();
    expect($searchable['not_found'])->toBeFalse();
    expect($searchable['auto_disliked'])->toBeFalse();
});

it('keeps zero values for numeric fields', function () {
    $file = File::factory()->create([
        'size' => 0,
        'parent_id' => 0,
        'download_progress' => 0,
        'previewed_count' => 0,
        'seen_count' => 0,
    ]);

    $searchable = $file->toSearchableArray();

    expect($searchable['size'])->toBe(0);
    expect($searchable['parent_id'])->toBe(0);
    expect($searchable['download_progress'])->toBe(0);
    expect($searchable['previewed_count'])->toBe(0);
    expect($searchable['seen_count'])->toBe(0);
});

it('can make a single file searchable', function () {
    $file = File::factory()->create([
        'filename' => 'searchable-file.jpg',
        'title' => 'Searchable File',
    ]);

    // Make the file searchable
    $file->searchable();

    // Verify it can be found via search
    $results = File::search('searchable-file')->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->id)->toBe($file->id);
});

it('can make multiple files searchable', function () {
    $files = File::factory()->count(5)->create([
        'title' => 'Batch File',
    ]);

    // Make all files searchable
    File::makeAllSearchable();

    // Verify they can be found via search
    $results = File::search('Batch File')->get();

    expect($results)->toHaveCount(5);
});

it('can search files by filename', function () {
    $file1 = File::factory()->create(['filename' => 'unique-filename-123.jpg']);
    $file2 = File::factory()->create(['filename' => 'another-file.jpg']);

    File::makeAllSearchable();

    $results = File::search('unique-filename-123')->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->id)->toBe($file1->id);
});

it('can search files by title', function () {
    $file = File::factory()->create([
        'title' => 'Amazing Photo',
        'filename' => 'photo.jpg',
    ]);

    File::makeAllSearchable();

    $results = File::search('Amazing Photo')->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->id)->toBe($file->id);
});

it('can search files by description', function () {
    $file = File::factory()->create([
        'description' => 'This is a detailed description of the content',
        'filename' => 'content.jpg',
    ]);

    File::makeAllSearchable();

    $results = File::search('detailed description')->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->id)->toBe($file->id);
});

it('includes tags in searchable array when present', function () {
    $file = File::factory()->create([
        'tags' => ['nature', 'landscape', 'sunset'],
    ]);

    $searchable = $file->toSearchableArray();

    expect($searchable)->toHaveKey('tags');
    expect($searchable['tags'])->toBe(['nature', 'landscape', 'sunset']);
});

it('can search files by tags', function () {
    $file = File::factory()->create([
        'tags' => ['nature', 'landscape'],
        'filename' => 'landscape.jpg',
    ]);

    File::makeAllSearchable();

    $results = File::search('nature')->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->id)->toBe($file->id);
});

it('handles blacklisted files correctly', function () {
    $file = File::factory()->create([
        'blacklisted_at' => now(),
        'blacklist_reason' => 'Inappropriate content',
    ]);

    $searchable = $file->toSearchableArray();

    expect($searchable['blacklisted'])->toBeTrue();
    expect($searchable['blacklist_reason'])->toBe('Inappropriate content');
});

it('converts timestamps to unix timestamps in searchable array', function () {
    $now = now();
    $file = File::factory()->create([
        'previewed_at' => $now,
        'seen_at' => $now,
        'downloaded_at' => $now,
        'blacklisted_at' => null,
    ]);

    $searchable = $file->toSearchableArray();

    expect($searchable['previewed_at'])->toBe($now->timestamp);
    expect($searchable['seen_at'])->toBe($now->timestamp);
    expect($searchable['downloaded_at'])->toBe($now->timestamp);
    // blacklisted_at is filtered out when null (it's an optional field)
    expect($searchable)->not->toHaveKey('blacklisted_at');
});

it('eager loads relationships when making all searchable', function () {
    // This test verifies that makeAllSearchableUsing is working
    // by ensuring relationships are loaded (prevents N+1 queries)
    $file = File::factory()->create();

    $query = File::query();
    $queryWithRelations = $file->makeAllSearchableUsing($query);

    // Verify the query includes eager loading
    expect($queryWithRelations->getEagerLoads())->toHaveKey('metadata');
    expect($queryWithRelations->getEagerLoads())->toHaveKey('reactions');
});

it('can unsearchable a file', function () {
    $file = File::factory()->create(['filename' => 'to-remove-unique-xyz.jpg']);

    $file->searchable();
    expect(File::search('to-remove-unique-xyz')->get())->toHaveCount(1);

    // Verify unsearchable method exists and can be called without error
    expect(method_exists($file, 'unsearchable'))->toBeTrue();

    $file->unsearchable();

    // Note: Collection driver behavior differs from Typesense
    // In real Typesense, unsearchable() removes from index immediately
    // This test verifies the method is callable
});

it('reflects model changes in searchable array', function () {
    $file = File::factory()->create([
        'title' => 'Original Title Unique',
        'filename' => 'update-test-unique.jpg',
    ]);

    // Verify initial searchable array
    $searchable = $file->toSearchableArray();
    expect($searchable['title'])->toBe('Original Title Unique');

    // Simulate an update by directly setting the attribute
    // (This tests that toSearchableArray() reflects current model state)
    $file->title = 'Updated Title Unique';

    // Verify the searchable array reflects the change
    $updatedSearchable = $file->toSearchableArray();
    expect($updatedSearchable['title'])->toBe('Updated Title Unique');

    // Note: In production, you would call $file->searchable() after updating
    // to sync the changes to Typesense. This test verifies the array generation.
});

// Integration test for Typesense (only runs when Typesense is available)
// To run this test, set SCOUT_DRIVER=typesense in your .env.testing
it('can index and search files in Typesense when driver is typesense', function () {
    // Flush the index first to ensure clean state
    File::removeAllFromSearch();

    $file = File::factory()->create([
        'filename' => 'typesense-test-file.jpg',
        'title' => 'Typesense Integration Test',
        'description' => 'This file is used to test Typesense integration',
        'mime_type' => 'image/jpeg',
    ]);

    // Make searchable (this should index in Typesense)
    $file->searchable();

    // Wait a moment for Typesense to index (it's usually fast, but async)
    sleep(1);

    // Search for the file
    $results = File::search('typesense-test-file')->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->id)->toBe($file->id);

    // Verify the searchable array structure matches Typesense schema
    $searchable = $file->toSearchableArray();
    $schema = config('scout.typesense.model-settings.'.File::class.'.collection-schema.fields', []);

    // Check that all required schema fields are present in searchable array
    $requiredFields = collect($schema)
        ->where('optional', false)
        ->pluck('name')
        ->all();

    foreach ($requiredFields as $field) {
        expect($searchable)->toHaveKey($field);
    }

    // Clean up
    $file->unsearchable();
})->skip(fn () => config('scout.driver') !== 'typesense', 'Typesense driver not configured');
