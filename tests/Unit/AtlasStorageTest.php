<?php

use App\Support\AtlasStorage;
use Illuminate\Support\Facades\Storage;

uses(Tests\TestCase::class);

it('builds segmented paths with sanitized filenames', function () {
    $paths = new AtlasStorage;

    expect($paths->segmentedPath(AtlasStorage::DOWNLOADS, 'bad:name.jpg', 'abcdef1234'))
        ->toBe('downloads/ab/cd/bad-name.jpg')
        ->and($paths->segmentedPath(AtlasStorage::IMPORTS, 'track.mp3', '123456'))
        ->toBe('imports/12/34/track.mp3');
});

it('keeps downloads and imports as the only managed top-level namespaces', function () {
    expect(AtlasStorage::namespaces())->toBe([
        AtlasStorage::DOWNLOADS,
        AtlasStorage::IMPORTS,
    ]);
});

it('builds derived paths inside the source file parent directory', function () {
    $paths = new AtlasStorage;

    expect($paths->derivedPath('downloads/12/34/myfile.jpg', 'preview', 'myfile.jpg'))
        ->toBe('downloads/12/34/preview/myfile.jpg')
        ->and($paths->derivedPath('imports/ab/cd/audio.wav', 'conversions', 'audio.mp3'))
        ->toBe('imports/ab/cd/conversions/audio.mp3');
});

it('requires a derived directory name', function () {
    (new AtlasStorage)->derivedPath('downloads/12/34/myfile.jpg', '', 'myfile.jpg');
})->throws(InvalidArgumentException::class);

it('normalizes filename extensions consistently', function () {
    $paths = new AtlasStorage;

    expect($paths->storedFilename('image', 'jpg'))->toBe('image.jpg')
        ->and($paths->storedFilename('image.JPG', 'jpg'))->toBe('image.JPG')
        ->and($paths->filenameWithExtension('movie.webm', 'mp4'))->toBe('movie.mp4');
});

it('generates random stored filenames with normalized extensions', function () {
    $paths = new AtlasStorage;

    expect($paths->randomStoredFilename('MP3'))->toMatch('/^[A-Za-z0-9]{40}\.mp3$/')
        ->and($paths->randomStoredFilename())->toMatch('/^[A-Za-z0-9]{40}\.bin$/');
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
