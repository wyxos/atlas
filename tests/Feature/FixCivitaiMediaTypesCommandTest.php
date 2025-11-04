<?php

use App\Jobs\FixCivitaiMediaType;
use App\Models\File;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

it('dispatches fix jobs and handles filenames with query tokens', function () {
    Storage::fake('atlas_app');

    $webpBody = base64_decode('UklGRiQAAABXRUJQVlA4ICQAAAAQAAAAHAAAAABwAQCdASoIAAgAAkA4JaQAA3AA/vuUAAA=');
    Storage::disk('atlas_app')->put('downloads/sample.mp4', $webpBody);

    $file = File::factory()->create([
        'source' => 'CivitAI',
        'url' => 'https://image.civitai.com/foo.mp4',
        'filename' => 'sample.mp4?token=abc',
        'path' => 'downloads/sample.mp4?token=abc',
        'mime_type' => 'video/mp4',
    ]);

    Bus::fake();

    Artisan::call('media:fix-civitai-types');

    Bus::assertDispatched(FixCivitaiMediaType::class, function ($job) use ($file) {
        return $job->fileId === $file->id;
    });

    $job = Bus::dispatched(FixCivitaiMediaType::class)->first();
    expect($job)->not->toBeNull();
    $job->handle();

    $file->refresh();

    expect($file->filename)->toBe('sample.webp')
        ->and($file->path)->toBe('downloads/sample.webp')
        ->and($file->mime_type)->toBe('image/webp');

    Storage::disk('atlas_app')->assertMissing('downloads/sample.mp4?token=abc');
    Storage::disk('atlas_app')->assertMissing('downloads/sample.mp4');
    Storage::disk('atlas_app')->assertExists('downloads/sample.webp');
});

