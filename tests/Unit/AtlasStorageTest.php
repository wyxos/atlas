<?php

use App\Support\AtlasStorage;
use Illuminate\Support\Facades\Storage;

uses(Tests\TestCase::class);

it('builds segmented paths with sanitized filenames', function () {
    $paths = new AtlasStorage;

    expect($paths->segmentedPath(AtlasStorage::DOWNLOADS, 'bad:name.jpg', 'abcdef1234'))
        ->toBe('downloads/ab/cd/bad-name.jpg')
        ->and($paths->segmentedPath(AtlasStorage::IMPORTS, 'track.mp3', '123456'))
        ->toBe('imports/12/34/track.mp3')
        ->and($paths->segmentedPath(AtlasStorage::CONVERSIONS, 'video.preview.mp4', 'feedface'))
        ->toBe('conversions/fe/ed/video.preview.mp4');
});

it('normalizes filename extensions consistently', function () {
    $paths = new AtlasStorage;

    expect($paths->storedFilename('image', 'jpg'))->toBe('image.jpg')
        ->and($paths->storedFilename('image.JPG', 'jpg'))->toBe('image.JPG')
        ->and($paths->variantFilename('movie.mp4', 'streamable', 'mp4'))->toBe('movie.streamable.mp4');
});

it('adds suffixes when a segmented path already exists', function () {
    Storage::fake('atlas');
    $paths = new AtlasStorage;
    $disk = Storage::disk('atlas');

    $disk->put('imports/ab/cd/photo.jpg', 'first');

    expect($paths->uniqueSegmentedPath($disk, AtlasStorage::IMPORTS, 'photo.jpg', 'abcdef'))
        ->toBe('imports/ab/cd/photo-2.jpg');
});

it('exposes atlas root and app root paths separately', function () {
    $root = rtrim(sys_get_temp_dir(), '\\/').DIRECTORY_SEPARATOR.'atlas-root';
    config()->set('atlas.root', $root);
    $paths = new AtlasStorage;

    expect($paths->rootPath())->toBe($root)
        ->and($paths->appRootPath())->toBe($root.DIRECTORY_SEPARATOR.'.app');
});
