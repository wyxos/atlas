<?php

use App\Http\Resources\FileResource;
use App\Models\File;
use App\Models\FileMetadata;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Storage::fake('atlas-app');
});

function resourceFile(array $attributes = [], ?FileMetadata $metadata = null): File
{
    $attributes = array_merge([
        'id' => 201,
        'downloaded' => false,
        'path' => null,
        'preview_path' => null,
        'poster_path' => null,
        'url' => null,
        'preview_url' => null,
        'listing_metadata' => null,
        'detail_metadata' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes);

    $file = new File;
    $file->forceFill($attributes);
    $file->id = (int) $attributes['id'];
    $file->created_at = $attributes['created_at'];
    $file->updated_at = $attributes['updated_at'];

    if ($metadata) {
        $file->setRelation('metadata', $metadata);
    } else {
        $file->setRelation('metadata', null);
    }

    return $file;
}

it('includes absolute preview path when preview path is set', function () {
    $file = resourceFile([
        'path' => 'downloads/aa/bb/example.jpg',
        'preview_path' => 'thumbnails/aa/bb/example.preview.jpg',
    ]);

    $data = FileResource::make($file)->toArray(Request::create('https://atlas.test/files'));

    $disk = Storage::disk(config('downloads.disk'));

    expect($data['absolute_path'])->toBe($disk->path($file->path));
    expect($data['absolute_preview_path'])->toBe($disk->path($file->preview_path));
});

it('includes width and height when available in metadata payload', function () {
    $file = resourceFile(metadata: new FileMetadata([
        'payload' => ['width' => 123, 'height' => 456],
    ]));

    $data = FileResource::make($file)->toArray(Request::create('https://atlas.test/files'));

    expect($data['width'])->toBe(123);
    expect($data['height'])->toBe(456);
});

it('prefers downloaded and preview file routes for downloaded files', function () {
    $file = resourceFile([
        'downloaded' => true,
        'path' => 'downloads/aa/bb/example.mp4',
        'preview_path' => 'downloads/aa/bb/example.preview.mp4',
        'url' => 'https://www.youtube.com/watch?v=example',
        'preview_url' => 'https://www.youtube.com/watch?v=example',
        'mime_type' => 'video/mp4',
    ]);

    $data = FileResource::make($file)->toArray(Request::create('https://atlas.test/files'));

    expect($data['disk_url'])->toBe("/api/files/{$file->id}/downloaded")
        ->and($data['preview_file_url'])->toBe("/api/files/{$file->id}/preview")
        ->and($data['file_url'])->toBe("/api/files/{$file->id}/downloaded")
        ->and($data['preview_url'])->toBe("/api/files/{$file->id}/preview");
});
