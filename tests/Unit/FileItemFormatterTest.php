<?php

use App\Models\File;
use App\Services\FileItemFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses preview route for downloaded video thumbnails', function () {
    $file = File::factory()->create([
        'mime_type' => 'video/mp4',
        'downloaded' => true,
        'path' => 'downloads/aa/bb/test.mp4',
        'preview_path' => 'downloads/aa/bb/test.preview.mp4',
        'poster_path' => 'downloads/aa/bb/test.poster.jpg',
    ]);

    $items = FileItemFormatter::format([$file], 1);

    expect($items)->toHaveCount(1);

    $item = $items[0];

    expect($item['src'])->toBe(route('api.files.preview', ['file' => $file->id]));
    expect($item['preview'])->toBe(route('api.files.preview', ['file' => $file->id]));
    expect($item['original'])->toBe(route('api.files.downloaded', ['file' => $file->id]));
});
