<?php

use App\Models\Album;
use App\Models\Artist;
use App\Models\Cover;
use App\Models\File;
use App\Models\FileMetadata;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;



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

it('audio navigation route is accessible and returns audio page', function () {
    // Create and authenticate a user
    $user = User::factory()->create();
    $this->actingAs($user);

    // Test that the audio route is accessible
    $response = $this->get('/audio');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Audio')
        ->has('files')
        ->has('search')
    );
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

it('can create a cover for file with album', function () {
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

    // Create a fake uploaded file
    $uploadedFile = \Illuminate\Http\UploadedFile::fake()->image('new-cover.jpg');

    // Make request to create cover
    $response = $this->post(route('covers.create', [
        'fileId' => $file->id
    ]), [
        'file' => $uploadedFile
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Cover created successfully');

    // Verify the cover was created and attached to the album
    $album->refresh();
    expect($album->covers)->toHaveCount(1);
    expect($album->covers->first()->coverable_type)->toBe(Album::class);
    expect($album->covers->first()->coverable_id)->toBe($album->id);
});

it('can create a cover for file without album', function () {
    // Create and authenticate a user
    $user = User::factory()->create();
    $this->actingAs($user);

    // Create a file without any album
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

    // Make request to create cover
    $response = $this->post(route('covers.create', [
        'fileId' => $file->id
    ]), [
        'file' => $uploadedFile
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Cover created successfully');

    // Verify the cover was created and attached to the file
    $file->refresh();
    expect($file->covers)->toHaveCount(1);
    expect($file->covers->first()->coverable_type)->toBe(File::class);
    expect($file->covers->first()->coverable_id)->toBe($file->id);
});

it('returns error when creating cover for non-existent file', function () {
    // Create and authenticate a user
    $user = User::factory()->create();
    $this->actingAs($user);

    // Create a fake uploaded file
    $uploadedFile = \Illuminate\Http\UploadedFile::fake()->image('new-cover.jpg');

    // Make request to create cover for non-existent file
    $response = $this->post(route('covers.create', [
        'fileId' => 999
    ]), [
        'file' => $uploadedFile
    ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors(['file' => 'File not found']);
});

it('can toggle laughed at status on a file', function () {
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
        'funny' => false,
    ]);

    // Toggle laughed at to true
    $response = $this->postJson(route('audio.laughed-at', ['file' => $file->id]));

    $response->assertStatus(200);
    $response->assertJson([
        'loved' => false,
        'liked' => false,
        'disliked' => false,
        'funny' => true,
    ]);

    // Verify the file was updated in database
    $file->refresh();
    expect($file->funny)->toBe(true);
    expect($file->laughed_at)->not->toBeNull();

    // Toggle laughed at to false
    $response = $this->postJson(route('audio.laughed-at', ['file' => $file->id]));

    $response->assertStatus(200);
    $response->assertJson([
        'loved' => false,
        'liked' => false,
        'disliked' => false,
        'funny' => false,
    ]);

    // Verify the file was updated in database
    $file->refresh();
    expect($file->funny)->toBe(false);
    expect($file->laughed_at)->toBeNull();
});

it('resets other reactions when laughed at is toggled on', function () {
    // Create and authenticate a user
    $user = User::factory()->create();
    $this->actingAs($user);

    // Create a file with other reactions set
    $file = File::create([
        'source' => 'test',
        'filename' => 'test-audio.mp3',
        'path' => '/path/to/test-audio.mp3',
        'size' => 1024,
        'mime_type' => 'audio/mpeg',
        'hash' => 'file123',
        'loved' => true,
        'loved_at' => now(),
        'liked' => true,
        'liked_at' => now(),
        'disliked' => true,
        'disliked_at' => now(),
        'funny' => false,
    ]);

    // Toggle laughed at to true
    $response = $this->postJson(route('audio.laughed-at', ['file' => $file->id]));

    $response->assertStatus(200);
    $response->assertJson([
        'loved' => false,
        'liked' => false,
        'disliked' => false,
        'funny' => true,
    ]);

    // Verify all other reactions were reset
    $file->refresh();
    expect($file->loved)->toBe(false);
    expect($file->loved_at)->toBeNull();
    expect($file->liked)->toBe(false);
    expect($file->liked_at)->toBeNull();
    expect($file->disliked)->toBe(false);
    expect($file->disliked_at)->toBeNull();
    expect($file->funny)->toBe(true);
    expect($file->laughed_at)->not->toBeNull();
});

it('returns 404 for non-existent file when toggling laughed at', function () {
    // Create and authenticate a user
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->postJson(route('audio.laughed-at', ['file' => 999]));

    $response->assertStatus(404);
});
