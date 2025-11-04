<?php

use App\Jobs\FixCivitaiMediaType;
use App\Models\File;
use Illuminate\Support\Facades\Storage;

it('detects actual mime type from disk and updates image files', function () {
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

    (new FixCivitaiMediaType($file->id))->handle();

    $file->refresh();

    expect($file->filename)->toBe('sample.webp')
        ->and($file->path)->toBe('downloads/sample.webp')
        ->and($file->mime_type)->toBe('image/webp');
});

it('detects video content and preserves mp4 extension', function () {
    Storage::fake('atlas_app');

    $mp4Body = hex2bin('000000186674797069736f6d0000020069736f6d6d7034310000000866726565');
    Storage::disk('atlas_app')->put('downloads/video.mp4', $mp4Body);

    $file = File::factory()->create([
        'source' => 'CivitAI',
        'url' => 'https://image.civitai.com/video.mp4',
        'filename' => 'video.mp4',
        'path' => 'downloads/video.mp4',
        'mime_type' => 'image/webp',
    ]);

    (new FixCivitaiMediaType($file->id))->handle();

    $file->refresh();

    expect($file->filename)->toBe('video.mp4')
        ->and($file->path)->toBe('downloads/video.mp4')
        ->and($file->mime_type)->toBe('video/mp4');
});

it('updates mp4 labelled downloads that contain png data', function () {
    Storage::fake('atlas_app');

    $pngBody = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGMAAQAABQABDQottAAAAABJRU5ErkJggg==');
    Storage::disk('atlas_app')->put('downloads/sample.mp4', $pngBody);

    $file = File::factory()->create([
        'source' => 'CivitAI',
        'url' => 'https://image.civitai.com/sample.mp4',
        'filename' => 'sample.mp4?token=png',
        'path' => 'downloads/sample.mp4?token=png',
        'mime_type' => 'video/mp4',
    ]);

    (new FixCivitaiMediaType($file->id))->handle();

    $file->refresh();

    expect($file->filename)->toBe('sample.png')
        ->and($file->path)->toBe('downloads/sample.png')
        ->and($file->mime_type)->toBe('image/png');
});
