<?php

use App\Models\File;
use App\Services\CivitAiImages;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('atlas_app');
});

it('validates video download with correct mp4 extension', function () {
    $file = File::factory()->create([
        'source' => 'CivitAI',
        'downloaded' => true,
        'path' => 'downloads/test.mp4',
        'mime_type' => 'video/mp4',
        'ext' => 'mp4',
        'listing_metadata' => ['type' => 'video'],
    ]);

    $service = new CivitAiImages;

    expect($service->validateDownload($file))->toBeTrue();
});

it('validates video download with incorrect webp extension', function () {
    $file = File::factory()->create([
        'source' => 'CivitAI',
        'downloaded' => true,
        'path' => 'downloads/test.webp',
        'mime_type' => 'image/webp',
        'ext' => 'webp',
        'listing_metadata' => ['type' => 'video'],
    ]);

    $service = new CivitAiImages;

    expect($service->validateDownload($file))->toBeFalse();
});

it('validates non-video files as valid', function () {
    $file = File::factory()->create([
        'source' => 'CivitAI',
        'downloaded' => true,
        'path' => 'downloads/test.jpg',
        'mime_type' => 'image/jpeg',
        'ext' => 'jpg',
        'listing_metadata' => ['type' => 'image'],
    ]);

    $service = new CivitAiImages;

    expect($service->validateDownload($file))->toBeTrue();
});

it('validates non-downloaded files as valid', function () {
    $file = File::factory()->create([
        'source' => 'CivitAI',
        'downloaded' => false,
        'path' => null,
        'listing_metadata' => ['type' => 'video'],
    ]);

    $service = new CivitAiImages;

    expect($service->validateDownload($file))->toBeTrue();
});

it('fixes video download by downloading correct mp4 file', function () {
    Http::fake([
        'https://civitai.com/images/81113576' => Http::response(
            file_get_contents(base_path('tests/fixtures/civitai-image-81113576.html')),
            200,
            ['Content-Type' => 'text/html']
        ),
        'https://media.example.test/civitai/81113576/transcode=true,original=true,quality=90/falsified-video.mp4' => Http::response('fake video content', 200),
    ]);

    Storage::disk('atlas_app')->put('downloads/test.webp', 'fake webp content');

    $file = File::factory()->create([
        'source' => 'CivitAI',
        'downloaded' => true,
        'path' => 'downloads/test.webp',
        'filename' => 'test.webp',
        'mime_type' => 'image/webp',
        'ext' => 'webp',
        'url' => 'https://example.com/test.webp',
        'referrer_url' => 'https://civitai.com/images/81113576',
        'listing_metadata' => ['type' => 'video'],
    ]);

    $service = new CivitAiImages;

    $result = $service->fixDownload($file);

    expect($result)->toBeTrue();
    $file->refresh();
    expect($file->path)->toBe('downloads/test.mp4');
    expect($file->filename)->toBe('test.mp4');
    expect($file->ext)->toBe('mp4');
    expect($file->mime_type)->toBe('video/mp4');
    expect(Storage::disk('atlas_app')->exists('downloads/test.mp4'))->toBeTrue();
    expect(Storage::disk('atlas_app')->exists('downloads/test.webp'))->toBeFalse();
});

it('returns false when url resolver cannot resolve', function () {
    Http::fake([
        'https://civitai.com/images/999' => Http::response('<html><body>No video</body></html>', 200),
    ]);

    $file = File::factory()->create([
        'source' => 'CivitAI',
        'downloaded' => true,
        'path' => 'downloads/test.webp',
        'filename' => 'test.webp',
        'mime_type' => 'image/webp',
        'ext' => 'webp',
        'referrer_url' => 'https://civitai.com/images/999',
        'listing_metadata' => ['type' => 'video'],
    ]);

    $service = new CivitAiImages;

    expect($service->fixDownload($file))->toBeFalse();
});

it('returns false for non-video files', function () {
    $file = File::factory()->create([
        'source' => 'CivitAI',
        'downloaded' => true,
        'path' => 'downloads/test.jpg',
        'listing_metadata' => ['type' => 'image'],
    ]);

    $service = new CivitAiImages;

    expect($service->fixDownload($file))->toBeFalse();
});

it('returns false when file is already correct', function () {
    $file = File::factory()->create([
        'source' => 'CivitAI',
        'downloaded' => true,
        'path' => 'downloads/test.mp4',
        'filename' => 'test.mp4',
        'mime_type' => 'video/mp4',
        'ext' => 'mp4',
        'listing_metadata' => ['type' => 'video'],
    ]);

    $service = new CivitAiImages;

    expect($service->fixDownload($file))->toBeFalse();
});
