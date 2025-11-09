<?php

use App\Models\File;
use App\Support\FileListingFormatter;

uses(Tests\TestCase::class);

it('flags resolution required when video has image thumbnail', function () {
    $file = File::factory()->make([
        'mime_type' => 'video/mp4',
        'url' => 'https://cdn.example.com/file.jpeg',
        'thumbnail_url' => 'https://cdn.example.com/thumb.mp4',
        'source' => 'CivitAI',
        'listing_metadata' => [],
        'detail_metadata' => [],
        'not_found' => false,
    ]);

    $file->setAttribute('id', 101);

    $result = FileListingFormatter::format($file, [], 1);

    expect($result)
        ->toHaveKey('resolutionRequired', true);
});

it('does not flag resolution when preview and original are video', function () {
    $file = File::factory()->make([
        'mime_type' => 'video/mp4',
        'url' => 'https://cdn.example.com/video.mp4',
        'thumbnail_url' => 'https://cdn.example.com/thumb.mp4',
        'source' => 'CivitAI',
        'listing_metadata' => [],
        'detail_metadata' => [],
        'not_found' => false,
    ]);

    $file->setAttribute('id', 102);

    $result = FileListingFormatter::format($file, [], 1);

    expect($result)
        ->toHaveKey('resolutionRequired', false);
});

it('does not flag resolution when source is not civitai', function () {
    $file = File::factory()->make([
        'mime_type' => 'video/mp4',
        'url' => 'https://cdn.example.com/file.jpeg',
        'thumbnail_url' => 'https://cdn.example.com/thumb.mp4',
        'source' => 'Other',
        'listing_metadata' => [],
        'detail_metadata' => [],
        'not_found' => false,
    ]);

    $file->setAttribute('id', 103);

    $result = FileListingFormatter::format($file, [], 1);

    expect($result)
        ->toHaveKey('resolutionRequired', false);
});
