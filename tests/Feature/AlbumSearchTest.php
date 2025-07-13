<?php

use App\Models\Album;
use App\Models\File;

it('creates searchable array with correct data types for album', function () {
    $album = Album::factory()->create([
        'name' => 'Test Album',
    ]);

    // Create some files associated with this album
    $files = File::factory()->count(3)->create([
        'mime_type' => 'audio/mp3',
    ]);

    $album->files()->attach($files);

    $searchableArray = $album->toSearchableArray();

    // Test required fields
    expect($searchableArray['id'])->toBeString();
    expect($searchableArray['name'])->toBe('Test Album');
    expect($searchableArray['created_at'])->toBeInt();
    expect($searchableArray['updated_at'])->toBeInt();

    // Test file counts
    expect($searchableArray['files_count'])->toBeInt()->toBe(3);
    expect($searchableArray['audio_files_count'])->toBeInt()->toBe(3);
});

it('handles album with no files correctly', function () {
    $album = Album::factory()->create([
        'name' => 'Empty Album',
    ]);

    $searchableArray = $album->toSearchableArray();

    // Test required fields
    expect($searchableArray['id'])->toBeString();
    expect($searchableArray['name'])->toBe('Empty Album');
    expect($searchableArray['created_at'])->toBeInt();
    expect($searchableArray['updated_at'])->toBeInt();

    // Test file counts should be 0
    expect($searchableArray['files_count'])->toBeInt()->toBe(0);
    expect($searchableArray['audio_files_count'])->toBeInt()->toBe(0);
});

it('counts only audio files for audio_files_count', function () {
    $album = Album::factory()->create([
        'name' => 'Mixed Media Album',
    ]);

    // Create audio files
    $audioFiles = File::factory()->count(2)->create([
        'mime_type' => 'audio/mp3',
    ]);

    // Create non-audio files
    $videoFiles = File::factory()->count(3)->create([
        'mime_type' => 'video/mp4',
    ]);

    $album->files()->attach($audioFiles->merge($videoFiles));

    $searchableArray = $album->toSearchableArray();

    // Total files should be 5, but audio files should be 2
    expect($searchableArray['files_count'])->toBeInt()->toBe(5);
    expect($searchableArray['audio_files_count'])->toBeInt()->toBe(2);
});

it('can generate searchable array without errors', function () {
    $album = Album::factory()->create([
        'name' => 'Test Album for Search',
    ]);

    // This should not throw any exceptions
    $searchableArray = null;
    expect(function () use ($album, &$searchableArray) {
        $searchableArray = $album->toSearchableArray();
        return $searchableArray;
    })->not->toThrow(Exception::class);

    expect($searchableArray)->toBeArray();
    expect($searchableArray)->toHaveKey('id');
    expect($searchableArray)->toHaveKey('name');
});

it('loads files relationship when not already loaded', function () {
    $album = Album::factory()->create([
        'name' => 'Relationship Test Album',
    ]);

    $files = File::factory()->count(2)->create([
        'mime_type' => 'audio/mp3',
    ]);

    $album->files()->attach($files);

    // Fresh instance without loaded relationships
    $freshAlbum = Album::find($album->id);

    expect($freshAlbum->relationLoaded('files'))->toBeFalse();

    $searchableArray = $freshAlbum->toSearchableArray();

    // Should have loaded the relationship and counted files correctly
    expect($searchableArray['files_count'])->toBeInt()->toBe(2);
    expect($searchableArray['audio_files_count'])->toBeInt()->toBe(2);
});
