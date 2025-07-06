<?php

use App\Models\Album;
use App\Models\Artist;
use App\Models\Cover;
use App\Models\File;
use App\Models\FileMetadata;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can get file details with all relationships loaded', function () {
    // Create and authenticate a user
    $user = User::factory()->create();
    $this->actingAs($user);

    // Create a file
    $file = File::create([
        'source' => 'test',
        'filename' => 'test-audio.mp3',
        'path' => '/path/to/test-audio.mp3',
        'size' => 1024,
        'mime_type' => 'audio/mpeg',
        'hash' => 'file123',
    ]);

    // Create metadata for the file
    $metadata = FileMetadata::create([
        'file_id' => $file->id,
        'payload' => ['title' => 'Test Song', 'artist' => 'Test Artist'],
    ]);

    // Create an artist and album
    $artist = Artist::create(['name' => 'Test Artist']);
    $album = Album::create(['name' => 'Test Album']);

    // Associate file with artist and album
    $file->artists()->attach($artist);
    $file->albums()->attach($album);

    // Create covers for file
    $fileCover = Cover::create([
        'path' => 'covers/file-cover.jpg',
        'hash' => 'file-cover-hash',
        'coverable_id' => $file->id,
        'coverable_type' => File::class,
    ]);

    // Create covers for artist
    $artistCover = Cover::create([
        'path' => 'covers/artist-cover.jpg',
        'hash' => 'artist-cover-hash',
        'coverable_id' => $artist->id,
        'coverable_type' => Artist::class,
    ]);

    // Create covers for album
    $albumCover = Cover::create([
        'path' => 'covers/album-cover.jpg',
        'hash' => 'album-cover-hash',
        'coverable_id' => $album->id,
        'coverable_type' => Album::class,
    ]);

    // Make request to getDetails endpoint
    $response = $this->getJson(route('audio.details', ['file' => $file->id]));

    $response->assertStatus(200);

    $data = $response->json();

    // Assert that all relationships are loaded
    expect($data)->toHaveKey('metadata');
    expect($data)->toHaveKey('covers');
    expect($data)->toHaveKey('artists');
    expect($data)->toHaveKey('albums');

    // Assert metadata is loaded
    expect($data['metadata'])->not->toBeNull();
    expect($data['metadata']['payload']['title'])->toBe('Test Song');

    // Assert file covers are loaded
    expect($data['covers'])->toHaveCount(1);
    expect($data['covers'][0]['path'])->toBe('covers/file-cover.jpg');

    // Assert artists and their covers are loaded
    expect($data['artists'])->toHaveCount(1);
    expect($data['artists'][0]['name'])->toBe('Test Artist');
    expect($data['artists'][0]['covers'])->toHaveCount(1);
    expect($data['artists'][0]['covers'][0]['path'])->toBe('covers/artist-cover.jpg');

    // Assert albums and their covers are loaded
    expect($data['albums'])->toHaveCount(1);
    expect($data['albums'][0]['name'])->toBe('Test Album');
    expect($data['albums'][0]['covers'])->toHaveCount(1);
    expect($data['albums'][0]['covers'][0]['path'])->toBe('covers/album-cover.jpg');
});

it('returns file details even when no covers exist', function () {
    // Create and authenticate a user
    $user = User::factory()->create();
    $this->actingAs($user);

    // Create a file without covers
    $file = File::create([
        'source' => 'test',
        'filename' => 'test-audio-no-covers.mp3',
        'path' => '/path/to/test-audio-no-covers.mp3',
        'size' => 1024,
        'mime_type' => 'audio/mpeg',
        'hash' => 'file456',
    ]);

    // Create metadata for the file
    $metadata = FileMetadata::create([
        'file_id' => $file->id,
        'payload' => ['title' => 'Test Song No Covers'],
    ]);

    // Make request to getDetails endpoint
    $response = $this->getJson(route('audio.details', ['file' => $file->id]));

    $response->assertStatus(200);

    $data = $response->json();

    // Assert that relationships are loaded but empty
    expect($data)->toHaveKey('metadata');
    expect($data)->toHaveKey('covers');
    expect($data)->toHaveKey('artists');
    expect($data)->toHaveKey('albums');

    expect($data['metadata'])->not->toBeNull();
    expect($data['covers'])->toHaveCount(0);
    expect($data['artists'])->toHaveCount(0);
    expect($data['albums'])->toHaveCount(0);
});

it('returns 404 for non-existent file', function () {
    // Create and authenticate a user
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->getJson(route('audio.details', ['file' => 999]));

    $response->assertStatus(404);
});

it('can update a cover associated with file album', function () {
    // Create and authenticate a user
    $user = User::factory()->create();
    $this->actingAs($user);

    // Create a file
    $file = File::create([
        'source' => 'test',
        'filename' => 'test-audio.mp3',
        'path' => '/path/to/test-audio.mp3',
        'size' => 1024,
        'mime_type' => 'audio/mpeg',
        'hash' => 'file123',
    ]);

    // Create an album and associate with file
    $album = Album::create(['name' => 'Test Album']);
    $file->albums()->attach($album);

    // Create a cover for the album
    $cover = Cover::create([
        'path' => 'covers/old-cover.jpg',
        'hash' => 'old-cover-hash',
        'coverable_id' => $album->id,
        'coverable_type' => Album::class,
    ]);

    // Create a fake uploaded file
    $uploadedFile = \Illuminate\Http\UploadedFile::fake()->image('new-cover.jpg');

    // Make request to update cover
    $response = $this->post(route('covers.update', [
        'coverId' => $cover->id
    ]), [
        'file' => $uploadedFile
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Cover updated successfully');

    // Verify the cover was updated
    $cover->refresh();
    expect($cover->path)->not->toBe('covers/old-cover.jpg');
    expect($cover->hash)->not->toBe('old-cover-hash');
});

it('can update any cover regardless of association', function () {
    // Create and authenticate a user
    $user = User::factory()->create();
    $this->actingAs($user);

    // Create a file
    $file = File::create([
        'source' => 'test',
        'filename' => 'test-audio.mp3',
        'path' => '/path/to/test-audio.mp3',
        'size' => 1024,
        'mime_type' => 'audio/mpeg',
        'hash' => 'file123',
    ]);

    // Create an album NOT associated with the file
    $unrelatedAlbum = Album::create(['name' => 'Unrelated Album']);

    // Create a cover for the unrelated album
    $cover = Cover::create([
        'path' => 'covers/unrelated-cover.jpg',
        'hash' => 'unrelated-cover-hash',
        'coverable_id' => $unrelatedAlbum->id,
        'coverable_type' => Album::class,
    ]);

    // Create a fake uploaded file
    $uploadedFile = \Illuminate\Http\UploadedFile::fake()->image('new-cover.jpg');

    // Make request to update cover (should succeed with simplified logic)
    $response = $this->post(route('covers.update', [
        'coverId' => $cover->id
    ]), [
        'file' => $uploadedFile
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Cover updated successfully');

    // Verify the cover was updated
    $cover->refresh();
    expect($cover->path)->not->toBe('covers/unrelated-cover.jpg');
    expect($cover->hash)->not->toBe('unrelated-cover-hash');
});

it('returns error for non-existent cover', function () {
    // Create and authenticate a user
    $user = User::factory()->create();
    $this->actingAs($user);

    // Create a file
    $file = File::create([
        'source' => 'test',
        'filename' => 'test-audio.mp3',
        'path' => '/path/to/test-audio.mp3',
        'size' => 1024,
        'mime_type' => 'audio/mpeg',
        'hash' => 'file123',
    ]);

    // Create a fake uploaded file
    $uploadedFile = \Illuminate\Http\UploadedFile::fake()->image('new-cover.jpg');

    // Make request to update non-existent cover
    $response = $this->post(route('covers.update', [
        'coverId' => 999
    ]), [
        'file' => $uploadedFile
    ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors(['cover' => 'Cover not found']);
});
