<?php

use App\Models\Album;
use App\Models\AlbumCover;
use App\Models\Container;
use App\Models\File;
use App\Models\FileMetadata;
use App\Services\FileItemFormatter;
use App\Support\FileApiPath;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\TestCase;

uses(TestCase::class);

function formatterFile(array $attributes = []): File
{
    $attributes = array_merge([
        'id' => 1,
        'filename' => 'test-file',
        'downloaded' => false,
        'path' => null,
        'preview_path' => null,
        'poster_path' => null,
        'url' => null,
        'preview_url' => null,
        'mime_type' => null,
        'listing_metadata' => null,
    ], $attributes);

    $file = new File;
    $file->forceFill($attributes);
    $file->id = (int) $attributes['id'];

    $file->setRelation('containers', new EloquentCollection);
    $file->setRelation('albums', new EloquentCollection);
    $file->setRelation('metadata', null);

    return $file;
}

function formatterAlbumWithDefaultCover(int $coverId): Album
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

function formatterContainer(array $attributes = []): Container
{
    $attributes = array_merge([
        'id' => 1,
        'type' => 'User',
        'source' => 'CivitAI',
        'source_id' => 'atlasUser',
        'referrer' => 'https://civitai.com/user/atlasUser',
    ], $attributes);

    $container = new Container;
    $container->forceFill($attributes);
    $container->id = (int) $attributes['id'];

    return $container;
}

it('uses preview route for downloaded video previews', function () {
    $file = formatterFile([
        'id' => 101,
        'mime_type' => 'video/mp4',
        'downloaded' => true,
        'path' => 'downloads/aa/bb/test.mp4',
        'preview_path' => 'downloads/aa/bb/preview/test.mp4',
        'poster_path' => 'downloads/aa/bb/preview/test.jpg',
    ]);

    $items = FileItemFormatter::format([$file], 1);

    expect($items)->toHaveCount(1);

    $item = $items[0];

    expect($item['src'])->toBe(FileApiPath::preview($file->id));
    expect($item['preview'])->toBe(FileApiPath::preview($file->id));
    expect($item['original'])->toBe(FileApiPath::downloaded($file->id));
});

it('treats application/mp4 as video for remote items', function () {
    $file = formatterFile([
        'id' => 102,
        'mime_type' => 'application/mp4',
        'url' => 'https://image.civitai.com/example/video.mp4',
        'preview_url' => 'https://image.civitai.com/example/preview.mp4',
        'downloaded' => false,
        'path' => null,
    ]);

    $items = FileItemFormatter::format([$file], 1);
    $item = $items[0];

    expect($item['media_kind'])->toBe('video');
    expect($item['type'])->toBe('video');
    expect($item['src'])->toBe('https://image.civitai.com/example/preview.mp4');
    expect($item['original'])->toBe('https://image.civitai.com/example/video.mp4');
});

it('uses an album cover preview for downloaded audio files when available', function () {
    $file = formatterFile([
        'id' => 103,
        'mime_type' => 'audio/mpeg',
        'ext' => 'mp3',
        'downloaded' => true,
        'path' => 'downloads/aa/bb/test.mp3',
    ]);
    $file->setRelation('albums', new EloquentCollection([
        formatterAlbumWithDefaultCover(501),
    ]));

    $items = FileItemFormatter::format([$file], 1);
    $item = $items[0];

    expect($item['media_kind'])->toBe('audio');
    expect($item['type'])->toBe('image'); // Vibe loader expects image/video only
    expect($item['src'])->toBe(FileApiPath::albumCover(501));
    expect($item['preview'])->toBe(FileApiPath::albumCover(501));
    expect($item['thumbnail'])->toBe(FileApiPath::albumCover(501));
    expect($item['original'])->toBe(FileApiPath::downloaded($file->id));
});

it('falls back to an icon preview for downloaded audio files without covers', function () {
    $file = formatterFile([
        'id' => 103,
        'mime_type' => 'audio/mpeg',
        'ext' => 'mp3',
        'downloaded' => true,
        'path' => 'downloads/aa/bb/test.mp3',
    ]);

    $items = FileItemFormatter::format([$file], 1);
    $item = $items[0];

    expect($item['media_kind'])->toBe('audio');
    expect($item['type'])->toBe('image'); // Vibe loader expects image/video only
    expect($item['src'])->toBe(FileApiPath::icon($file->id));
    expect($item['preview'])->toBe(FileApiPath::icon($file->id));
    expect($item['original'])->toBe(FileApiPath::downloaded($file->id));
});

it('uses an icon preview for non-image/video files', function () {
    $file = formatterFile([
        'id' => 104,
        'mime_type' => 'application/pdf',
        'ext' => 'pdf',
        'downloaded' => true,
        'path' => 'downloads/aa/bb/test.pdf',
    ]);

    $items = FileItemFormatter::format([$file], 1);
    $item = $items[0];

    expect($item['media_kind'])->toBe('file');
    expect($item['type'])->toBe('image'); // Vibe loader expects image/video only
    expect($item['src'])->toBe(FileApiPath::icon($file->id));
    expect($item['original'])->toBe(FileApiPath::downloaded($file->id));
});

it('includes backend-owned browse payloads for supported containers', function () {
    $file = formatterFile([
        'id' => 105,
        'mime_type' => 'image/jpeg',
        'url' => 'https://image.civitai.com/example/image.jpeg',
        'preview_url' => 'https://image.civitai.com/example/preview.jpeg',
    ]);

    $file->setRelation('containers', new EloquentCollection([
        formatterContainer(),
    ]));

    $items = FileItemFormatter::format([$file], 1, [
        'feed' => 'online',
        'service' => 'civit-ai-images',
        'limit' => '20',
        'sort' => 'Newest',
    ]);

    expect($items[0]['containers'][0]['browse_tab'])->toBe([
        'label' => 'CivitAI Images: User atlasUser - 1',
        'params' => [
            'feed' => 'online',
            'service' => 'civit-ai-images',
            'page' => 1,
            'limit' => '20',
            'sort' => 'Newest',
            'username' => 'atlasUser',
        ],
    ]);
});

it('extracts prompt text from nested metadata payloads', function () {
    $file = formatterFile([
        'id' => 106,
        'mime_type' => 'image/jpeg',
        'url' => 'https://image.civitai.com/example/nested.jpeg',
        'preview_url' => 'https://image.civitai.com/example/nested-preview.jpeg',
    ]);

    $file->setRelation('metadata', new FileMetadata([
        'payload' => [
            'meta' => [
                'prompt' => 'nested prompt',
            ],
        ],
    ]));

    $items = FileItemFormatter::format([$file], 1);

    expect($items[0]['metadata'])->toBe([
        'prompt' => 'nested prompt',
    ]);
});

it('includes minimal source access state for DeviantArt watcher-gated library items', function () {
    $file = formatterFile([
        'id' => 107,
        'source' => 'deviantart.com',
        'source_id' => 'B80091C0-EC22-C82D-1E54-FB6FC80E5924',
        'referrer_url' => 'https://www.deviantart.com/exampleartist/art/example-123',
        'mime_type' => 'image/jpeg',
        'url' => 'https://images.example.test/blurred.jpg',
        'preview_url' => 'https://images.example.test/blurred-preview.jpg',
        'listing_metadata' => [
            'premium_folder_data' => [
                'type' => 'watchers',
                'has_access' => false,
            ],
        ],
    ]);

    $items = FileItemFormatter::format([$file], 1, [
        'feed' => 'local',
        'source' => 'deviantart.com',
    ]);

    expect($items[0])
        ->toHaveKey('source', 'deviantart.com')
        ->toHaveKey('source_id', 'B80091C0-EC22-C82D-1E54-FB6FC80E5924')
        ->toHaveKey('referrer_url', 'https://www.deviantart.com/exampleartist/art/example-123')
        ->not->toHaveKey('listing_metadata')
        ->and($items[0]['source_access'])->toBe([
            'provider' => 'deviantart',
            'access_type' => 'watchers',
            'has_access' => false,
            'requires_watch' => true,
            'can_unwatch' => false,
        ])
        ->and($items[0]['capabilities'])->toMatchArray([
            'refresh_source_media' => true,
            'watch_source_and_refresh' => true,
            'unwatch_source_account' => true,
        ]);
});
