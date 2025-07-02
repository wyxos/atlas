<?php

use App\Models\File;
use App\Models\FileMetadata;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can create a file', function () {
    $file = File::create([
        'source' => 'YouTube',
        'url' => 'https://example.com/video.mp4',
        'filename' => 'example-video.mp4',
    ]);

    expect(File::where([
        'id' => $file->id,
        'source' => 'YouTube',
        'url' => 'https://example.com/video.mp4',
        'filename' => 'example-video.mp4',
    ])->exists())->toBeTrue();
});

it('has correct attributes and casts', function () {
    $file = File::create([
        'source' => 'NAS',
        'url' => 'https://nas.example.com/file.mp4',
        'filename' => 'file.mp4',
        'tags' => ['tag1', 'tag2'],
        'is_blacklisted' => true,
        'liked' => true,
        'downloaded' => true,
        'download_progress' => 100,
        'seen_preview_at' => now(),
    ]);

    $retrievedFile = File::find($file->id);

    expect($retrievedFile->tags)->toBeArray();
    expect($retrievedFile->tags)->toEqual(['tag1', 'tag2']);

    expect($retrievedFile->is_blacklisted)->toBeBool();
    expect($retrievedFile->is_blacklisted)->toBeTrue();

    expect($retrievedFile->liked)->toBeBool();
    expect($retrievedFile->liked)->toBeTrue();

    expect($retrievedFile->downloaded)->toBeBool();
    expect($retrievedFile->downloaded)->toBeTrue();

    expect($retrievedFile->download_progress)->toBeInt();
    expect($retrievedFile->download_progress)->toBe(100);

    expect($retrievedFile->seen_preview_at)->toBeInstanceOf(Carbon::class);
});

it('has a working metadata relationship', function () {
    // Create a file
    $file = File::create([
        'source' => 'YouTube',
        'filename' => 'metadata-test.mp4',
    ]);

    // Create metadata for the file
    $metadata = FileMetadata::create([
        'file_id' => $file->id,
        'payload' => ['duration' => '01:30:00', 'resolution' => '1080p'],
        'is_review_required' => true,
    ]);

    // Test relationship from File to FileMetadata
    expect($file->metadata)->toBeInstanceOf(FileMetadata::class);
    expect($file->metadata->id)->toBe($metadata->id);
    expect($file->metadata->payload)->toBe(['duration' => '01:30:00', 'resolution' => '1080p']);
    expect($file->metadata->is_review_required)->toBeTrue();

    // Test relationship from FileMetadata to File
    expect($metadata->file)->toBeInstanceOf(File::class);
    expect($metadata->file->id)->toBe($file->id);
    expect($metadata->file->filename)->toBe('metadata-test.mp4');
});
