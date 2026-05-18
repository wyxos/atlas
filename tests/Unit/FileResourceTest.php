<?php

use App\Http\Resources\FileResource;
use App\Models\Album;
use App\Models\AlbumCover;
use App\Models\File;
use App\Models\FileMetadata;
use App\Support\FileApiPath;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Storage::fake('atlas');
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

function resourceAlbumWithDefaultCover(int $coverId): Album
{
    $album = new Album;
    $album->forceFill([
        'id' => 1,
        'name' => 'Album',
        'normalized_name' => 'album',
    ]);
    $album->id = 1;

    $cover = new AlbumCover;
    $cover->forceFill([
        'id' => $coverId,
        'album_id' => $album->id,
        'path' => 'imports/aa/bb/covers/cover.jpg',
        'mime_type' => 'image/jpeg',
        'is_default' => true,
    ]);
    $cover->id = $coverId;

    $album->setRelation('defaultCover', $cover);

    return $album;
}

it('includes absolute preview path when preview path is set', function () {
    $file = resourceFile([
        'path' => 'downloads/aa/bb/example.jpg',
        'preview_path' => 'downloads/aa/bb/preview/example.jpg',
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
        'preview_path' => 'downloads/aa/bb/preview/example.mp4',
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

it('includes an audio cover url when an album cover relation is loaded', function () {
    $file = resourceFile([
        'id' => 202,
        'mime_type' => 'audio/mpeg',
        'downloaded' => true,
        'path' => 'downloads/aa/bb/example.mp3',
    ]);
    $file->setRelation('albums', new EloquentCollection([
        resourceAlbumWithDefaultCover(701),
    ]));

    $data = FileResource::make($file)->toArray(Request::create('https://atlas.test/files'));

    expect($data['cover_url'])->toBe(FileApiPath::albumCover(701));
});

it('resolves imported local file absolute path from atlas storage', function () {
    $path = 'imports/aa/bb/local-track.mp3';
    Storage::disk('atlas')->put($path, 'atlas-track');

    $file = resourceFile([
        'path' => $path,
        'downloaded' => false,
        'imported_at' => now(),
        'source' => 'local',
        'mime_type' => 'audio/mpeg',
    ]);

    $data = FileResource::make($file)->toArray(Request::create('https://atlas.test/files'));

    expect($data['absolute_path'])->toBe(realpath(Storage::disk('atlas')->path($path)));
});
