<?php

use App\Http\Resources\FileResource;
use App\Models\File;
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
