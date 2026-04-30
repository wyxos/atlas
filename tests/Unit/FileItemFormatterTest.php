<?php

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
    $file->setRelation('metadata', null);

    return $file;
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

it('uses preview route for downloaded video thumbnails', function () {
    $file = formatterFile([
        'id' => 101,
        'mime_type' => 'video/mp4',
        'downloaded' => true,
        'path' => 'downloads/aa/bb/test.mp4',
        'preview_path' => 'downloads/aa/bb/test.preview.mp4',
        'poster_path' => 'downloads/aa/bb/test.poster.jpg',
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

it('uses an icon preview for downloaded audio files but keeps the original URL', function () {
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
