<?php

use App\Models\Artist;
use App\Models\File;

it('creates searchable array with correct data types for artist', function () {
    $artist = Artist::factory()->create([
        'name' => 'Test Artist',
    ]);

    // Create some files associated with this artist
    $files = File::factory()->count(3)->create([
        'mime_type' => 'audio/mp3',
    ]);

    $artist->files()->attach($files);

    $searchableArray = $artist->toSearchableArray();

    // Test required fields
    expect($searchableArray['id'])->toBeString();
    expect($searchableArray['name'])->toBe('Test Artist');
    expect($searchableArray['created_at'])->toBeInt();
    expect($searchableArray['updated_at'])->toBeInt();

    // Test file counts
    expect($searchableArray['files_count'])->toBeInt()->toBe(3);
    expect($searchableArray['audio_files_count'])->toBeInt()->toBe(3);
});

it('handles artist with no files correctly', function () {
    $artist = Artist::factory()->create([
        'name' => 'Empty Artist',
    ]);

    $searchableArray = $artist->toSearchableArray();

    // Test required fields
    expect($searchableArray['id'])->toBeString();
    expect($searchableArray['name'])->toBe('Empty Artist');
    expect($searchableArray['created_at'])->toBeInt();
    expect($searchableArray['updated_at'])->toBeInt();

    // Test file counts should be 0
    expect($searchableArray['files_count'])->toBeInt()->toBe(0);
    expect($searchableArray['audio_files_count'])->toBeInt()->toBe(0);
});

it('counts only audio files for audio_files_count', function () {
    $artist = Artist::factory()->create([
        'name' => 'Mixed Media Artist',
    ]);

    // Create audio files
    $audioFiles = File::factory()->count(2)->create([
        'mime_type' => 'audio/mp3',
    ]);

    // Create non-audio files
    $videoFiles = File::factory()->count(3)->create([
        'mime_type' => 'video/mp4',
    ]);

    $artist->files()->attach($audioFiles->merge($videoFiles));

    $searchableArray = $artist->toSearchableArray();

    // Total files should be 5, but audio files should be 2
    expect($searchableArray['files_count'])->toBeInt()->toBe(5);
    expect($searchableArray['audio_files_count'])->toBeInt()->toBe(2);
});

it('can generate searchable array without errors', function () {
    $artist = Artist::factory()->create([
        'name' => 'Test Artist for Search',
    ]);

    // This should not throw any exceptions
    $searchableArray = null;
    expect(function () use ($artist, &$searchableArray) {
        $searchableArray = $artist->toSearchableArray();
        return $searchableArray;
    })->not->toThrow(Exception::class);

    expect($searchableArray)->toBeArray();
    expect($searchableArray)->toHaveKey('id');
    expect($searchableArray)->toHaveKey('name');
});

it('loads files relationship when not already loaded', function () {
    $artist = Artist::factory()->create([
        'name' => 'Relationship Test Artist',
    ]);

    $files = File::factory()->count(2)->create([
        'mime_type' => 'audio/mp3',
    ]);

    $artist->files()->attach($files);

    // Fresh instance without loaded relationships
    $freshArtist = Artist::find($artist->id);

    expect($freshArtist->relationLoaded('files'))->toBeFalse();

    $searchableArray = $freshArtist->toSearchableArray();

    // Should have loaded the relationship and counted files correctly
    expect($searchableArray['files_count'])->toBeInt()->toBe(2);
    expect($searchableArray['audio_files_count'])->toBeInt()->toBe(2);
});
