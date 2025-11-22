<?php

use App\Models\File;
use App\Support\FilePreviewUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(Tests\TestCase::class);
uses(RefreshDatabase::class);

it('returns preview URL using file ID route when thumbnail_path exists', function () {
    Storage::fake('atlas_app');
    Storage::disk('atlas_app')->put('thumbnails/preview.webp', 'fake-preview-bytes');

    $file = File::factory()->create([
        'path' => 'media/original.mp4',
        'thumbnail_path' => 'thumbnails/preview.webp',
        'filename' => 'original.mp4',
    ]);

    $url = FilePreviewUrl::for($file);

    expect($url)->toBe(route('files.preview', ['file' => $file->id]));
    expect($url)->not->toContain('storage/atlas-app');
    expect($url)->not->toContain('thumbnails/preview.webp');
});

it('returns preview URL using file ID route when only path exists', function () {
    Storage::fake('atlas');
    Storage::disk('atlas')->put('media/preview.mp4', 'fake-preview-bytes');

    $file = File::factory()->create([
        'path' => 'media/preview.mp4',
        'thumbnail_path' => null,
        'filename' => 'preview.mp4',
    ]);

    $url = FilePreviewUrl::for($file);

    expect($url)->toBe(route('files.preview', ['file' => $file->id]));
    expect($url)->not->toContain('storage/atlas-app');
    expect($url)->not->toContain('media/preview.mp4');
});

it('returns null when file has no path', function () {
    $file = File::factory()->create([
        'path' => null,
        'thumbnail_path' => null,
    ]);

    $url = FilePreviewUrl::for($file);

    expect($url)->toBeNull();
});

it('returns null when file does not exist on any disk', function () {
    Storage::fake('atlas');
    Storage::fake('atlas_app');

    $file = File::factory()->create([
        'path' => 'media/missing.mp4',
        'thumbnail_path' => null,
    ]);

    $url = FilePreviewUrl::for($file);

    expect($url)->toBeNull();
});

it('checks atlas_app disk first, then atlas disk', function () {
    Storage::fake('atlas');
    Storage::disk('atlas')->put('media/preview.mp4', 'fake-preview-bytes');

    $file = File::factory()->create([
        'path' => 'media/preview.mp4',
        'thumbnail_path' => null,
    ]);

    $url = FilePreviewUrl::for($file);

    expect($url)->toBe(route('files.preview', ['file' => $file->id]));
});

it('handles paths with leading slashes', function () {
    Storage::fake('atlas_app');
    Storage::disk('atlas_app')->put('thumbnails/preview.webp', 'fake-preview-bytes');

    $file = File::factory()->create([
        'path' => 'media/original.mp4',
        'thumbnail_path' => '/thumbnails/preview.webp',
        'filename' => 'original.mp4',
    ]);

    $url = FilePreviewUrl::for($file);

    expect($url)->toBe(route('files.preview', ['file' => $file->id]));
});
