<?php

use App\Events\StorageProcessingProgress;
use App\Jobs\ProcessImageJob;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('generates a 450px thumbnail and stores it on atlas_app disk', function () {
    Storage::fake('atlas');
    Storage::fake('atlas_app');

    $img = imagecreatetruecolor(800, 600);
    $bg = imagecolorallocate($img, 40, 120, 200);
    imagefilledrectangle($img, 0, 0, 800, 600, $bg);
    ob_start();
    imagejpeg($img, null, 90);
    $bytes = ob_get_clean();
    imagedestroy($img);

    Storage::disk('atlas')->put('photos/pic.jpg', $bytes);

    $fileRecord = File::create([
        'source' => 'local',
        'path' => 'photos/pic.jpg',
        'filename' => 'pic.jpg',
        'ext' => 'jpg',
        'mime_type' => 'image/jpeg',
        'size' => strlen($bytes),
    ]);

    Cache::put('storage_processing:1:total', 1);
    Cache::put('storage_processing:1:done', 0);
    Cache::put('storage_processing:1:failed', 0);

    $job = new ProcessImageJob(1, 'atlas', $fileRecord->fresh());

    $job->handle();

    $thumbnails = Storage::disk('atlas_app')->files('thumbnails');
    expect($thumbnails)->not->toBeEmpty();

    $fileRecord->refresh();
    expect($fileRecord->thumbnail_path)->not->toBeNull();
    expect(Storage::disk('atlas_app')->exists($fileRecord->thumbnail_path))->toBeTrue();

    $metadata = $fileRecord->metadata;
    expect($metadata)->not->toBeNull();
    expect($metadata->payload['width'])->toBe(800);
    expect($metadata->payload['height'])->toBe(600);
    expect($metadata->payload['thumbnail_width'])->toBe(450);
    expect($metadata->payload['thumbnail_height'])->toBe(338);
});

it('skips processing when cancellation flag is set', function (): void {
    Storage::fake('atlas');
    Storage::fake('atlas_app');

    $bytes = 'fake-image';
    Storage::disk('atlas')->put('photos/pic.jpg', $bytes);

    $fileRecord = File::create([
        'source' => 'local',
        'path' => 'photos/pic.jpg',
        'filename' => 'pic.jpg',
        'ext' => 'jpg',
        'mime_type' => 'image/jpeg',
        'size' => strlen($bytes),
    ]);

    Cache::flush();
    Cache::put('storage_scan:1:cancel', true, now()->addMinutes(10));

    Event::fake([StorageProcessingProgress::class]);

    $job = new ProcessImageJob(1, 'atlas', $fileRecord->fresh());
    $job->handle();

    expect(Storage::disk('atlas_app')->files('thumbnails'))->toBe([]);

    $fileRecord->refresh();
    expect($fileRecord->thumbnail_path)->toBeNull();

    expect(Cache::get('storage_processing:1:done'))->toBe(0);
    expect(Cache::get('storage_processing:1:failed'))->toBe(0);

    Event::assertNotDispatched(StorageProcessingProgress::class);
});

it('skips regeneration when thumbnail already exists with dimensions', function (): void {
    Storage::fake('atlas');
    Storage::fake('atlas_app');

    $img = imagecreatetruecolor(800, 600);
    $bg = imagecolorallocate($img, 12, 80, 160);
    imagefilledrectangle($img, 0, 0, 800, 600, $bg);
    ob_start();
    imagejpeg($img, null, 90);
    $bytes = ob_get_clean();
    imagedestroy($img);

    Storage::disk('atlas')->put('photos/pic.jpg', $bytes);
    Storage::disk('atlas_app')->put('thumbnails/existing.webp', 'thumb');

    $fileRecord = File::create([
        'source' => 'local',
        'path' => 'photos/pic.jpg',
        'filename' => 'pic.jpg',
        'ext' => 'jpg',
        'mime_type' => 'image/jpeg',
        'size' => strlen($bytes),
        'thumbnail_path' => 'thumbnails/existing.webp',
    ]);

    $fileRecord->metadata()->create([
        'payload' => [
            'width' => 800,
            'height' => 600,
            'thumbnail_width' => 450,
            'thumbnail_height' => 338,
        ],
        'is_review_required' => false,
        'is_extracted' => true,
    ]);

    Cache::put('storage_processing:1:total', 1);
    Cache::put('storage_processing:1:done', 0);
    Cache::put('storage_processing:1:failed', 0);

    $job = new ProcessImageJob(1, 'atlas', $fileRecord->fresh());

    $before = Storage::disk('atlas_app')->allFiles();
    $job->handle();
    $after = Storage::disk('atlas_app')->allFiles();

    expect($after)->toEqual($before);

    $fileRecord->refresh();
    expect($fileRecord->thumbnail_path)->toBe('thumbnails/existing.webp');
    expect(Storage::disk('atlas_app')->exists('thumbnails/existing.webp'))->toBeTrue();
});
