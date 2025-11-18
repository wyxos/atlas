<?php

use App\Models\File;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('atlas_app');
});

it('finds and fixes affected files', function () {
    $html = file_get_contents(base_path('tests/fixtures/civitai-image-81113576.html'));
    $mp4Url = 'https://media.example.test/civitai/81113576/transcode=true,original=true,quality=90/falsified-video.mp4';

    Http::fake([
        'https://civitai.com/images/81113576' => Http::response($html, 200, ['Content-Type' => 'text/html']),
        'https://civitai.com/images/81113577' => Http::response($html, 200, ['Content-Type' => 'text/html']),
        $mp4Url => Http::response('fake video content', 200),
    ]);

    Storage::disk('atlas_app')->put('downloads/test1.webp', 'fake content');
    Storage::disk('atlas_app')->put('downloads/test2.webp', 'fake content');

    $file1 = File::factory()->create([
        'source' => 'CivitAI',
        'downloaded' => true,
        'path' => 'downloads/test1.webp',
        'filename' => 'test1.webp',
        'ext' => 'webp',
        'mime_type' => 'image/webp',
        'referrer_url' => 'https://civitai.com/images/81113576',
        'source_id' => '111',
        'listing_metadata' => ['type' => 'video'],
    ]);

    $file2 = File::factory()->create([
        'source' => 'CivitAI',
        'downloaded' => true,
        'path' => 'downloads/test2.webp',
        'filename' => 'test2.webp',
        'ext' => 'webp',
        'mime_type' => 'image/webp',
        'referrer_url' => 'https://civitai.com/images/81113577',
        'source_id' => '222',
        'listing_metadata' => ['type' => 'video'],
    ]);

    Artisan::call('files:fix-civitai-video-downloads');

    $output = Artisan::output();
    expect($output)->toContain('Found 2 file(s) to process');
    expect($output)->toContain('Fixed');

    $files = File::where('ext', 'mp4')->get();
    expect($files)->toHaveCount(2);
});

it('shows dry run output without making changes', function () {
    Storage::disk('atlas_app')->put('downloads/test.webp', 'fake content');

    $file = File::factory()->create([
        'source' => 'CivitAI',
        'downloaded' => true,
        'path' => 'downloads/test.webp',
        'filename' => 'test.webp',
        'ext' => 'webp',
        'mime_type' => 'image/webp',
        'listing_metadata' => ['type' => 'video'],
    ]);

    Artisan::call('files:fix-civitai-video-downloads', ['--dry-run' => true]);

    $output = Artisan::output();
    expect($output)->toContain('DRY RUN MODE');
    expect($output)->toContain('Would fix');

    $file->refresh();
    expect($file->ext)->toBe('webp');
});

it('respects limit option', function () {
    Storage::disk('atlas_app')->put('downloads/test1.webp', 'fake content');
    Storage::disk('atlas_app')->put('downloads/test2.webp', 'fake content');

    File::factory()->create([
        'source' => 'CivitAI',
        'downloaded' => true,
        'path' => 'downloads/test1.webp',
        'ext' => 'webp',
        'listing_metadata' => ['type' => 'video'],
    ]);

    File::factory()->create([
        'source' => 'CivitAI',
        'downloaded' => true,
        'path' => 'downloads/test2.webp',
        'ext' => 'webp',
        'listing_metadata' => ['type' => 'video'],
    ]);

    Artisan::call('files:fix-civitai-video-downloads', ['--limit' => 1]);

    expect(Artisan::output())->toContain('Found 1 file(s) to process');
});

it('skips files that are not videos', function () {
    File::factory()->create([
        'source' => 'CivitAI',
        'downloaded' => true,
        'path' => 'downloads/test.jpg',
        'ext' => 'jpg',
        'listing_metadata' => ['type' => 'image'],
    ]);

    Artisan::call('files:fix-civitai-video-downloads');

    expect(Artisan::output())->toContain('No files to process');
});

it('skips files that are already correct', function () {
    File::factory()->create([
        'source' => 'CivitAI',
        'downloaded' => true,
        'path' => 'downloads/test.mp4',
        'ext' => 'mp4',
        'mime_type' => 'video/mp4',
        'listing_metadata' => ['type' => 'video'],
    ]);

    Artisan::call('files:fix-civitai-video-downloads');

    expect(Artisan::output())->toContain('No files to process');
});
