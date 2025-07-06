<?php

use App\Models\Album;
use App\Models\Artist;
use App\Models\Cover;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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
