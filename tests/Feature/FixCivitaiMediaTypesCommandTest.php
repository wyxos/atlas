<?php

use App\Jobs\FixCivitaiMediaType;
use App\Models\File;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

it('dispatches fix jobs for files matching criteria', function () {
    Storage::fake('atlas_app');
    Storage::fake('atlas');

    $file = File::factory()->create([
        'source' => 'CivitAI',
        'url' => 'https://image.civitai.com/foo.mp4',
        'referrer_url' => 'https://civitai.com/images/67559727',
        'thumbnail_url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/96b33e41-8a73-4faa-8ba2-02f65e80dc35/original=true/96b33e41-8a73-4faa-8ba2-02f65e80dc35.mp4',
        'filename' => 'sample.webp',
        'path' => 'downloads/sample.webp',
        'mime_type' => 'image/webp',
    ]);

    Bus::fake();

    Artisan::call('media:fix-civitai-types');

    Bus::assertDispatched(FixCivitaiMediaType::class, function ($job) use ($file) {
        return $job->fileId === $file->id;
    });
});

it('does not dispatch jobs for files that do not match criteria', function () {
    Storage::fake('atlas_app');
    Storage::fake('atlas');

    // File without mp4 in thumbnail_url
    $file1 = File::factory()->create([
        'source' => 'CivitAI',
        'url' => 'https://image.civitai.com/foo.jpg',
        'referrer_url' => 'https://civitai.com/images/123',
        'thumbnail_url' => 'https://image.civitai.com/thumb.jpg',
        'filename' => 'sample.webp',
        'path' => 'downloads/sample.webp',
        'mime_type' => 'image/webp',
    ]);

    // File with wrong mime type
    $file2 = File::factory()->create([
        'source' => 'CivitAI',
        'url' => 'https://image.civitai.com/foo.mp4',
        'referrer_url' => 'https://civitai.com/images/456',
        'thumbnail_url' => 'https://image.civitai.com/thumb.mp4',
        'filename' => 'sample.mp4',
        'path' => 'downloads/sample.mp4',
        'mime_type' => 'video/mp4',
    ]);

    // File without path
    $file3 = File::factory()->create([
        'source' => 'CivitAI',
        'url' => 'https://image.civitai.com/foo.mp4',
        'referrer_url' => 'https://civitai.com/images/789',
        'thumbnail_url' => 'https://image.civitai.com/thumb.mp4',
        'filename' => 'sample.webp',
        'path' => null,
        'mime_type' => 'image/webp',
    ]);

    Bus::fake();

    Artisan::call('media:fix-civitai-types');

    Bus::assertNothingDispatched();
});

it('reports no files found when criteria is not met', function () {
    Storage::fake('atlas_app');
    Storage::fake('atlas');

    File::factory()->create([
        'source' => 'CivitAI',
        'url' => 'https://image.civitai.com/foo.jpg',
        'thumbnail_url' => 'https://image.civitai.com/thumb.jpg',
        'mime_type' => 'image/jpeg',
        'path' => 'downloads/sample.jpg',
    ]);

    Bus::fake();

    $result = Artisan::call('media:fix-civitai-types');

    expect($result)->toBe(0);
    Bus::assertNothingDispatched();
});
