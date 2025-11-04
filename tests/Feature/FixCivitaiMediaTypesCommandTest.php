<?php

use App\Models\File;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

it('renames civitai files with incorrect extensions based on actual mime type', function () {
    Storage::fake('atlas_app');

    $webpBody = base64_decode('UklGRiQAAABXRUJQVlA4ICQAAAAQAAAAHAAAAABwAQCdASoIAAgAAkA4JaQAA3AA/vuUAAA=');

    Storage::disk('atlas_app')->put('downloads/sample.mp4', $webpBody);

    $file = File::factory()->create([
        'source' => 'CivitAI',
        'url' => 'https://image.civitai.com/foo.mp4',
        'filename' => 'sample.mp4',
        'path' => 'downloads/sample.mp4',
        'mime_type' => 'video/mp4',
    ]);

    Artisan::call('media:fix-civitai-types');

    $file->refresh();

    expect($file->filename)->toBe('sample.webp')
        ->and($file->path)->toBe('downloads/sample.webp')
        ->and($file->mime_type)->toBe('image/webp');

    Storage::disk('atlas_app')->assertMissing('downloads/sample.mp4');
    Storage::disk('atlas_app')->assertExists('downloads/sample.webp');
});

