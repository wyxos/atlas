<?php

use App\Models\Cover;
use App\Models\File;
use App\Models\Playlist;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can create a playlist', function () {
    $playlist = Playlist::create([
        'name' => 'My Awesome Playlist',
        'is_smart' => false,
        'smart_parameters' => null,
    ]);

    expect($playlist)->toBeInstanceOf(Playlist::class);
    expect($playlist->name)->toBe('My Awesome Playlist');
    expect($playlist->is_smart)->toBeFalse();
    expect($playlist->smart_parameters)->toBeNull();
});

it('can create a smart playlist', function () {
    $smartParameters = [
        'genre' => 'rock',
        'year' => ['min' => 2000, 'max' => 2020],
        'duration' => ['min' => 180]
    ];

    $playlist = Playlist::create([
        'name' => 'Smart Rock Playlist',
        'is_smart' => true,
        'smart_parameters' => $smartParameters,
    ]);

    expect($playlist)->toBeInstanceOf(Playlist::class);
    expect($playlist->name)->toBe('Smart Rock Playlist');
    expect($playlist->is_smart)->toBeTrue();
    expect($playlist->smart_parameters)->toBe($smartParameters);
});

it('casts is_smart to boolean', function () {
    $playlist = Playlist::create([
        'name' => 'Test Playlist',
        'is_smart' => 1,
        'smart_parameters' => null,
    ]);

    expect($playlist->is_smart)->toBeTrue();
    expect($playlist->is_smart)->toBeBool();
});

it('casts smart_parameters to array', function () {
    $smartParameters = ['genre' => 'jazz', 'limit' => 50];

    $playlist = Playlist::create([
        'name' => 'Jazz Playlist',
        'is_smart' => true,
        'smart_parameters' => $smartParameters,
    ]);

    expect($playlist->smart_parameters)->toBeArray();
    expect($playlist->smart_parameters)->toBe($smartParameters);
});

it('can have many covers', function () {
    $playlist = Playlist::create([
        'name' => 'Test Playlist',
        'is_smart' => false,
        'smart_parameters' => null,
    ]);

    $cover1 = Cover::create([
        'path' => '/path/to/playlist-cover1.jpg',
        'hash' => 'playlist-hash1',
        'coverable_id' => $playlist->id,
        'coverable_type' => Playlist::class,
    ]);

    $cover2 = Cover::create([
        'path' => '/path/to/playlist-cover2.jpg',
        'hash' => 'playlist-hash2',
        'coverable_id' => $playlist->id,
        'coverable_type' => Playlist::class,
    ]);

    expect($playlist->covers)->toHaveCount(2);
    expect($playlist->covers->first())->toBeInstanceOf(Cover::class);
    expect($playlist->covers->pluck('path')->toArray())->toContain('/path/to/playlist-cover1.jpg', '/path/to/playlist-cover2.jpg');
});

it('cover can belong to a playlist', function () {
    $playlist = Playlist::create([
        'name' => 'Test Playlist',
        'is_smart' => false,
        'smart_parameters' => null,
    ]);

    $cover = Cover::create([
        'path' => '/path/to/playlist-cover.jpg',
        'hash' => 'playlist123',
        'coverable_id' => $playlist->id,
        'coverable_type' => Playlist::class,
    ]);

    expect($cover->coverable)->toBeInstanceOf(Playlist::class);
    expect($cover->coverable->name)->toBe('Test Playlist');
});

it('has default value for is_smart', function () {
    $playlist = Playlist::create([
        'name' => 'Default Playlist',
    ]);

    expect($playlist->is_smart)->toBeFalse();
});

it('can have many files', function () {
    $playlist = Playlist::create([
        'name' => 'Test Playlist',
        'is_smart' => false,
        'smart_parameters' => null,
    ]);

    $file1 = File::create([
        'source' => 'test',
        'filename' => 'song1.mp3',
        'path' => '/path/to/song1.mp3',
        'size' => 1024,
        'mime_type' => 'audio/mpeg',
        'hash' => 'hash1',
    ]);

    $file2 = File::create([
        'source' => 'test',
        'filename' => 'song2.mp3',
        'path' => '/path/to/song2.mp3',
        'size' => 2048,
        'mime_type' => 'audio/mpeg',
        'hash' => 'hash2',
    ]);

    $playlist->files()->attach([$file1->id, $file2->id]);

    expect($playlist->files)->toHaveCount(2);
    expect($playlist->files->first())->toBeInstanceOf(File::class);
    expect($playlist->files->pluck('filename')->toArray())->toContain('song1.mp3', 'song2.mp3');
});

it('file can belong to many playlists', function () {
    $playlist1 = Playlist::create([
        'name' => 'Rock Playlist',
        'is_smart' => false,
        'smart_parameters' => null,
    ]);

    $playlist2 = Playlist::create([
        'name' => 'Favorites Playlist',
        'is_smart' => false,
        'smart_parameters' => null,
    ]);

    $file = File::create([
        'source' => 'test',
        'filename' => 'awesome-song.mp3',
        'path' => '/path/to/awesome-song.mp3',
        'size' => 3072,
        'mime_type' => 'audio/mpeg',
        'hash' => 'awesome-hash',
    ]);

    $file->playlists()->attach([$playlist1->id, $playlist2->id]);

    expect($file->playlists)->toHaveCount(2);
    expect($file->playlists->first())->toBeInstanceOf(Playlist::class);
    expect($file->playlists->pluck('name')->toArray())->toContain('Rock Playlist', 'Favorites Playlist');
});

it('can detach files from playlist', function () {
    $playlist = Playlist::create([
        'name' => 'Test Playlist',
        'is_smart' => false,
        'smart_parameters' => null,
    ]);

    $file = File::create([
        'source' => 'test',
        'filename' => 'test-song.mp3',
        'path' => '/path/to/test-song.mp3',
        'size' => 1024,
        'mime_type' => 'audio/mpeg',
        'hash' => 'test-hash',
    ]);

    $playlist->files()->attach($file->id);
    expect($playlist->files)->toHaveCount(1);

    $playlist->files()->detach($file->id);
    $playlist->refresh();
    expect($playlist->files)->toHaveCount(0);
});
