<?php

use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;



it('creates searchable array with correct data types for typesense', function () {
    $file = File::factory()->create([
        'source' => 'YouTube',
        'filename' => 'test-video.mp4',
        'title' => 'Test Video',
        'description' => 'A test video for search',
        'tags' => ['test', 'video'],
        'liked' => true,
        'liked_at' => now(),
        'size' => 1024,
        'is_blacklisted' => false,
    ]);

    $searchableArray = $file->toSearchableArray();

    // Test required fields
    expect($searchableArray['id'])->toBeString();
    expect($searchableArray['source'])->toBe('YouTube');
    expect($searchableArray['filename'])->toBe('test-video.mp4');
    expect($searchableArray['created_at'])->toBeInt();
    expect($searchableArray['updated_at'])->toBeInt();

    // Test optional fields with correct types
    expect($searchableArray['title'])->toBe('Test Video');
    expect($searchableArray['description'])->toBe('A test video for search');
    expect($searchableArray['tags'])->toBeArray();
    expect($searchableArray['tags'])->toEqual(['test', 'video']);
    expect($searchableArray['size'])->toBeInt()->toBe(1024);
    expect($searchableArray['liked'])->toBeBool()->toBeTrue();
    expect($searchableArray['is_blacklisted'])->toBeBool()->toBeFalse();

    // Test timestamp fields are integers (Unix timestamps)
    expect($searchableArray['liked_at'])->toBeInt();
    expect($searchableArray['liked_at'])->toBeGreaterThan(0);
});

it('handles null timestamp fields correctly', function () {
    $file = File::factory()->create([
        'source' => 'NAS',
        'filename' => 'test-file.jpg',
        'liked' => false,
        'liked_at' => null,
        'disliked_at' => null,
        'loved_at' => null,
        'downloaded_at' => null,
        'seen_preview_at' => null,
        'seen_file_at' => null,
    ]);

    $searchableArray = $file->toSearchableArray();

    // Null timestamp fields should not be included in the searchable array
    expect($searchableArray)->not->toHaveKey('liked_at');
    expect($searchableArray)->not->toHaveKey('disliked_at');
    expect($searchableArray)->not->toHaveKey('loved_at');
    expect($searchableArray)->not->toHaveKey('downloaded_at');
    expect($searchableArray)->not->toHaveKey('seen_preview_at');
    expect($searchableArray)->not->toHaveKey('seen_file_at');
});

it('handles tags array properly', function () {
    // Test with array tags
    $file1 = File::factory()->create([
        'filename' => 'test1.mp4',
        'tags' => ['tag1', 'tag2', 'tag3'],
    ]);

    $searchableArray1 = $file1->toSearchableArray();
    expect($searchableArray1['tags'])->toBeArray();
    expect($searchableArray1['tags'])->toEqual(['tag1', 'tag2', 'tag3']);

    // Test with null tags
    $file2 = File::factory()->create([
        'filename' => 'test2.mp4',
        'tags' => null,
    ]);

    $searchableArray2 = $file2->toSearchableArray();
    expect($searchableArray2)->not->toHaveKey('tags');

    // Test with empty tags
    $file3 = File::factory()->create([
        'filename' => 'test3.mp4',
        'tags' => [],
    ]);

    $searchableArray3 = $file3->toSearchableArray();
    expect($searchableArray3)->not->toHaveKey('tags');
});

it('can generate searchable array without errors', function () {
    $file = File::factory()->create([
        'source' => 'YouTube',
        'filename' => 'sync-test.mp4',
        'title' => 'Sync Test Video',
        'tags' => ['sync', 'test'],
        'liked' => true,
        'liked_at' => now(),
    ]);

    // This should not throw any exceptions
    $searchableArray = null;
    expect(function () use ($file, &$searchableArray) {
        $searchableArray = $file->toSearchableArray();

        return $searchableArray;
    })->not->toThrow(Exception::class);

    $searchableArray = $file->toSearchableArray();
    expect($searchableArray)->toBeArray();
    expect($searchableArray)->toHaveKey('id');
    expect($searchableArray)->toHaveKey('source');
    expect($searchableArray)->toHaveKey('filename');
});

it('maintains data type consistency across multiple records', function () {
    $files = File::factory()->count(3)->create([
        'liked' => true,
        'liked_at' => now(),
        'size' => 2048,
        'tags' => ['consistency', 'test'],
    ]);

    foreach ($files as $file) {
        $searchableArray = $file->toSearchableArray();

        // Verify consistent data types
        expect($searchableArray['id'])->toBeString();
        expect($searchableArray['size'])->toBeInt();
        expect($searchableArray['liked'])->toBeBool();
        expect($searchableArray['liked_at'])->toBeInt();
        expect($searchableArray['tags'])->toBeArray();
        expect($searchableArray['created_at'])->toBeInt();
        expect($searchableArray['updated_at'])->toBeInt();
    }
});

it('validates schema compatibility with typesense configuration', function () {
    $file = File::factory()->create([
        'source' => 'NAS',
        'source_id' => 'test-123',
        'url' => 'https://example.com/test.mp4',
        'referrer_url' => 'https://example.com',
        'path' => '/videos/test.mp4',
        'filename' => 'schema-test.mp4',
        'ext' => 'mp4',
        'size' => 1024000,
        'mime_type' => 'video/mp4',
        'hash' => 'abc123def456',
        'title' => 'Schema Test Video',
        'description' => 'Testing schema compatibility',
        'thumbnail_url' => 'https://example.com/thumb.jpg',
        'tags' => ['schema', 'test', 'typesense'],
        'parent_id' => 1,
        'chapter' => 'Chapter 1',
        'is_blacklisted' => false,
        'blacklist_reason' => null,
        'liked' => true,
        'liked_at' => now(),
        'disliked' => false,
        'disliked_at' => null,
        'loved' => false,
        'loved_at' => null,
        'downloaded' => true,
        'download_progress' => 100,
        'downloaded_at' => now(),
        'seen_preview_at' => now(),
        'seen_file_at' => now(),
    ]);

    $searchableArray = $file->toSearchableArray();

    // Get the expected schema from config
    $expectedSchema = config('scout.typesense.model-settings.App\Models\File.collection-schema.fields');
    $expectedFieldTypes = collect($expectedSchema)->pluck('type', 'name');

    // Validate each field matches expected type
    foreach ($searchableArray as $fieldName => $value) {
        $expectedType = $expectedFieldTypes->get($fieldName);

        if (! $expectedType) {
            continue; // Skip fields not in schema
        }

        switch ($expectedType) {
            case 'string':
                expect($value)->toBeString("Field {$fieldName} should be string");
                break;
            case 'int32':
            case 'int64':
                expect($value)->toBeInt("Field {$fieldName} should be integer");
                break;
            case 'bool':
                expect($value)->toBeBool("Field {$fieldName} should be boolean");
                break;
            case 'string[]':
                expect($value)->toBeArray("Field {$fieldName} should be array");
                break;
        }
    }
});

it('includes artist and album names in searchable array when relationships are loaded', function () {
    $file = File::factory()->create([
        'filename' => 'test-song.mp3',
        'title' => 'Test Song',
    ]);

    // Create artists and albums
    $artist1 = \App\Models\Artist::create(['name' => 'Test Artist 1']);
    $artist2 = \App\Models\Artist::create(['name' => 'Test Artist 2']);
    $album1 = \App\Models\Album::create(['name' => 'Test Album 1']);
    $album2 = \App\Models\Album::create(['name' => 'Test Album 2']);

    // Attach artists and albums to the file
    $file->artists()->attach([$artist1->id, $artist2->id]);
    $file->albums()->attach([$album1->id, $album2->id]);

    // Refresh the file to load relationships
    $file->refresh();

    $searchableArray = $file->toSearchableArray();

    // Test that artist names are included
    expect($searchableArray)->toHaveKey('artist_name');
    expect($searchableArray)->toHaveKey('artist_names');
    expect($searchableArray['artist_name'])->toBe('Test Artist 1');
    expect($searchableArray['artist_names'])->toBeArray();
    expect($searchableArray['artist_names'])->toContain('Test Artist 1');
    expect($searchableArray['artist_names'])->toContain('Test Artist 2');

    // Test that album names are included
    expect($searchableArray)->toHaveKey('album_name');
    expect($searchableArray)->toHaveKey('album_names');
    expect($searchableArray['album_name'])->toBe('Test Album 1');
    expect($searchableArray['album_names'])->toBeArray();
    expect($searchableArray['album_names'])->toContain('Test Album 1');
    expect($searchableArray['album_names'])->toContain('Test Album 2');
});

it('handles files without artists or albums in searchable array', function () {
    $file = File::factory()->create([
        'filename' => 'test-song-no-relations.mp3',
        'title' => 'Test Song Without Relations',
    ]);

    $searchableArray = $file->toSearchableArray();

    // Test that artist and album fields are not included when no relationships exist
    expect($searchableArray)->not->toHaveKey('artist_name');
    expect($searchableArray)->not->toHaveKey('artist_names');
    expect($searchableArray)->not->toHaveKey('album_name');
    expect($searchableArray)->not->toHaveKey('album_names');
});
