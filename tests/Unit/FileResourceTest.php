<?php

use App\Http\Resources\FileResource;
use App\Models\File;
use App\Models\FileMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('includes absolute preview path when preview path is set', function () {
    $file = File::factory()->create([
        'path' => 'downloads/aa/bb/example.jpg',
        'preview_path' => 'thumbnails/aa/bb/example.preview.jpg',
    ]);

    $data = FileResource::make($file)->toArray(request());

    $disk = Storage::disk(config('downloads.disk'));

    expect($data['absolute_path'])->toBe($disk->path($file->path));
    expect($data['absolute_preview_path'])->toBe($disk->path($file->preview_path));
});

it('includes width and height when available in metadata payload', function () {
    $file = File::factory()->create();

    FileMetadata::factory()->create([
        'file_id' => $file->id,
        'payload' => ['width' => 123, 'height' => 456],
    ]);

    $data = FileResource::make($file->fresh()->load('metadata'))->toArray(request());

    expect($data['width'])->toBe(123);
    expect($data['height'])->toBe(456);
});

it('prefers downloaded and preview file routes for downloaded files', function () {
    $file = File::factory()->create([
        'downloaded' => true,
        'path' => 'downloads/aa/bb/example.mp4',
        'preview_path' => 'downloads/aa/bb/example.preview.mp4',
        'url' => 'https://www.youtube.com/watch?v=example',
        'preview_url' => 'https://www.youtube.com/watch?v=example',
        'mime_type' => 'video/mp4',
    ]);

    $data = FileResource::make($file)->toArray(request());

    expect($data['disk_url'])->toBe("/api/files/{$file->id}/downloaded")
        ->and($data['preview_file_url'])->toBe("/api/files/{$file->id}/preview")
        ->and($data['file_url'])->toBe("/api/files/{$file->id}/downloaded")
        ->and($data['preview_url'])->toBe("/api/files/{$file->id}/preview");
});
