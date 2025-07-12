<?php

use App\Models\Album;
use App\Models\Artist;
use App\Models\Cover;
use App\Models\File;

it('can create a cover', function () {
    $cover = Cover::create([
        'path' => '/path/to/cover.jpg',
        'hash' => 'abc123',
        'coverable_id' => 1,
        'coverable_type' => Artist::class,
    ]);

    expect($cover)->toBeInstanceOf(Cover::class);
    expect($cover->path)->toBe('/path/to/cover.jpg');
    expect($cover->hash)->toBe('abc123');
    expect($cover->coverable_id)->toBe(1);
    expect($cover->coverable_type)->toBe(Artist::class);
});

it('can belong to an artist', function () {
    $artist = Artist::create(['name' => 'Test Artist']);

    $cover = Cover::create([
        'path' => '/path/to/artist-cover.jpg',
        'hash' => 'artist123',
        'coverable_id' => $artist->id,
        'coverable_type' => Artist::class,
    ]);

    expect($cover->coverable)->toBeInstanceOf(Artist::class);
    expect($cover->coverable->name)->toBe('Test Artist');
});

it('can belong to an album', function () {
    $album = Album::create(['name' => 'Test Album']);

    $cover = Cover::create([
        'path' => '/path/to/album-cover.jpg',
        'hash' => 'album123',
        'coverable_id' => $album->id,
        'coverable_type' => Album::class,
    ]);

    expect($cover->coverable)->toBeInstanceOf(Album::class);
    expect($cover->coverable->name)->toBe('Test Album');
});

it('artist can have many covers', function () {
    $artist = Artist::create(['name' => 'Test Artist']);

    $cover1 = Cover::create([
        'path' => '/path/to/cover1.jpg',
        'hash' => 'hash1',
        'coverable_id' => $artist->id,
        'coverable_type' => Artist::class,
    ]);

    $cover2 = Cover::create([
        'path' => '/path/to/cover2.jpg',
        'hash' => 'hash2',
        'coverable_id' => $artist->id,
        'coverable_type' => Artist::class,
    ]);

    expect($artist->covers)->toHaveCount(2);
    expect($artist->covers->first())->toBeInstanceOf(Cover::class);
    expect($artist->covers->pluck('path')->toArray())->toContain('/path/to/cover1.jpg', '/path/to/cover2.jpg');
});

it('album can have many covers', function () {
    $album = Album::create(['name' => 'Test Album']);

    $cover1 = Cover::create([
        'path' => '/path/to/album-cover1.jpg',
        'hash' => 'album-hash1',
        'coverable_id' => $album->id,
        'coverable_type' => Album::class,
    ]);

    $cover2 = Cover::create([
        'path' => '/path/to/album-cover2.jpg',
        'hash' => 'album-hash2',
        'coverable_id' => $album->id,
        'coverable_type' => Album::class,
    ]);

    expect($album->covers)->toHaveCount(2);
    expect($album->covers->first())->toBeInstanceOf(Cover::class);
    expect($album->covers->pluck('path')->toArray())->toContain('/path/to/album-cover1.jpg', '/path/to/album-cover2.jpg');
});

it('can belong to a file', function () {
    $file = File::create([
        'source' => 'test',
        'filename' => 'test-audio.mp3',
        'path' => '/path/to/test-audio.mp3',
        'size' => 1024,
        'mime_type' => 'audio/mpeg',
        'hash' => 'file123',
    ]);

    $cover = Cover::create([
        'path' => '/path/to/file-cover.jpg',
        'hash' => 'file-cover123',
        'coverable_id' => $file->id,
        'coverable_type' => File::class,
    ]);

    expect($cover->coverable)->toBeInstanceOf(File::class);
    expect($cover->coverable->filename)->toBe('test-audio.mp3');
});

it('file can have many covers', function () {
    $file = File::create([
        'source' => 'test',
        'filename' => 'test-audio.mp3',
        'path' => '/path/to/test-audio.mp3',
        'size' => 1024,
        'mime_type' => 'audio/mpeg',
        'hash' => 'file123',
    ]);

    $cover1 = Cover::create([
        'path' => '/path/to/file-cover1.jpg',
        'hash' => 'file-hash1',
        'coverable_id' => $file->id,
        'coverable_type' => File::class,
    ]);

    $cover2 = Cover::create([
        'path' => '/path/to/file-cover2.jpg',
        'hash' => 'file-hash2',
        'coverable_id' => $file->id,
        'coverable_type' => File::class,
    ]);

    expect($file->covers)->toHaveCount(2);
    expect($file->covers->first())->toBeInstanceOf(Cover::class);
    expect($file->covers->pluck('path')->toArray())->toContain('/path/to/file-cover1.jpg', '/path/to/file-cover2.jpg');
});
