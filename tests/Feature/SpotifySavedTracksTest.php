<?php

use App\Models\File;
use App\Models\FileMetadata;
use App\Services\SpotifySavedTracks;

test('transforms and persists spotify saved tracks', function () {
    $raw = json_decode(file_get_contents(base_path('tests/fixtures/spotify-me-tracks-page-1.json')), true);

    $service = new SpotifySavedTracks;
    $service->setParams(['limit' => 2, 'offset' => 0]);

    $formatted = $service->transform($raw);
    expect($formatted)->toBeArray()->toHaveKeys(['files', 'filter']);
    expect($formatted['files'])->toBeArray()->toHaveCount(2);

    foreach ($formatted['files'] as $item) {
        expect($item['file']['filename'])->toMatch('/^[A-Za-z0-9]{40}$/');
    }

    $items = $formatted['files'];
    // Persist
    $service->persists($items);

    expect(File::count())->toBe(2);
    expect(FileMetadata::count())->toBe(2);

    $first = File::first();
    expect($first->source)->toBe('Spotify');
    expect($first->mime_type)->toBe('audio/spotify');
    expect($first->listing_metadata)->toBeArray();

    // Upsert should not duplicate
    $service->persists($items);
    expect(File::count())->toBe(2);
    expect(FileMetadata::count())->toBe(2);
});
