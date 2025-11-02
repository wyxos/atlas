<?php

use App\Models\Album;
use App\Models\Artist;
use App\Models\File;
use App\Models\FileMetadata;
use App\Models\Playlist;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('playlist page requires authentication', function () {
    $user = User::factory()->create();
    $playlist = Playlist::firstOrCreate(['name' => 'All songs', 'user_id' => $user->id]);

    $response = $this->get(route('playlists.show', ['playlist' => $playlist->id]));
    $response->assertRedirect('/login');
});

test('playlist page renders for authenticated user', function () {
    $playlist = Playlist::firstOrCreate(['name' => 'All songs', 'user_id' => $this->user->id]);

    $response = $this->actingAs($this->user)->get(route('playlists.show', ['playlist' => $playlist->id]));

    $response->assertStatus(200)
        ->assertInertia(fn (Assert $page) => $page
            ->component('audio/Index')
            ->has('files')
            ->has('search')
            ->has('query')
        );
});

test('playlist page returns file ids only', function () {
    // Create some audio files
    $audioFiles = File::factory(5)->create([
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    $playlist = Playlist::firstOrCreate(['name' => 'All songs', 'user_id' => $this->user->id]);
    $playlist->files()->syncWithoutDetaching($audioFiles->pluck('id')->toArray());

    $response = $this->actingAs($this->user)->get(route('playlists.show', ['playlist' => $playlist->id]));

    $response->assertStatus(200)
        ->assertInertia(fn (Assert $page) => $page
            ->component('audio/Index')
            ->where('files', function ($files) {
                // Check that we get arrays with just id key
                foreach ($files as $file) {
                    if (! is_array($file) || ! isset($file['id'])) {
                        return false;
                    }
                    // Should not have other keys like filename, path, etc
                    if (count(array_keys($file)) > 1) {
                        return false;
                    }
                }

                return true;
            })
            ->where('search', [])
        );
});

test('playlist search with query parameter works and results are constrained to playlist', function () {
    // Configure Scout to use Typesense for this test
    useTypesense();
    resetTypesenseFileCollection();

    // Create test audio files with searchable content (both will be in the All songs playlist)
    $file1 = File::factory()->create([
        'filename' => 'power_rangers_theme.mp3',
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    $file2 = File::factory()->create([
        'filename' => 'another_song.mp3',
        'title' => 'Another Song',
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => now(),
    ]);

    // This matching-but-blacklisted file should NOT be considered part of the playlist
    $blacklistedMatch = File::factory()->create([
        'filename' => 'power_rangers_alt.mp3',
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => now(),
    ]);

    // Create metadata for better search
    FileMetadata::create([
        'file_id' => $file1->id,
        'payload' => [
            'title' => 'Power Rangers Theme Song',
            'artist' => 'Ron Wasserman',
        ],
    ]);
    FileMetadata::create([
        'file_id' => $blacklistedMatch->id,
        'payload' => [
            'title' => 'Power Rangers Theme Song (Blacklisted)',
            'artist' => 'Ron Wasserman',
        ],
    ]);

    // Ensure index reflects current data
    // Attach to playlist before indexing so playlist_ids is present in the index
    $playlist = Playlist::firstOrCreate(['name' => 'All songs', 'user_id' => $this->user->id]);
    $playlist->files()->syncWithoutDetaching([$file1->id, $file2->id]);

    // Re-index after membership update
    File::query()->searchable();

    $response = $this->actingAs($this->user)->get(route('playlists.show', ['playlist' => $playlist->id, 'query' => 'power rangers']));

    $response->assertStatus(200)
        ->assertInertia(fn (Assert $page) => $page
            ->component('audio/Index')
            ->has('files')
            ->has('search', 1)
            ->where('search.0.id', (int) $file1->id)
            ->where('query', 'power rangers')
        );
});

test('audio details endpoint returns full file data', function () {
    $file = File::factory()->create([
        'filename' => 'test.mp3',
        'mime_type' => 'audio/mpeg',
    ]);

    // Create metadata
    $metadata = FileMetadata::create([
        'file_id' => $file->id,
        'payload' => [
            'title' => 'Test Song',
            'duration' => 240.5,
        ],
    ]);

    // Create artist and album
    $artist = Artist::create(['name' => 'Test Artist']);
    $album = Album::create(['name' => 'Test Album']);

    $file->artists()->attach($artist);
    $file->albums()->attach($album);

    $response = $this->actingAs($this->user)
        ->getJson("/audio/{$file->id}/details");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'id',
            'filename',
            'path',
            'mime_type',
            'size',
            'loved',
            'liked',
            'disliked',
            'funny',
            'metadata' => [
                'payload',
            ],
            'artists',
            'albums',
            'covers',
        ])
        ->assertJson([
            'id' => $file->id,
            'loved' => false,
            'liked' => false,
            'disliked' => false,
            'funny' => false,
        ]);
});

test('audio batch details returns keyed models', function () {
    $files = File::factory(3)->create([
        'mime_type' => 'audio/mpeg',
    ]);

    $fileIds = $files->pluck('id')->toArray();

    $response = $this->actingAs($this->user)
        ->postJson('/audio/batch-details', [
            'file_ids' => $fileIds,
        ]);

    $response->assertStatus(200);

    $data = $response->json();

    // Check that response is keyed by file ID
    foreach ($fileIds as $id) {
        expect($data)->toHaveKey((string) $id);
        expect($data[(string) $id]['id'])->toBe($id);
    }
});

test('playlist excludes blacklisted files', function () {
    // Create normal audio file
    File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'blacklisted_at' => null,
        'not_found' => false,
    ]);

    // Create blacklisted audio file
    File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'blacklisted_at' => now(),
        'not_found' => false,
    ]);

    // Create not found audio file
    File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'blacklisted_at' => null,
        'not_found' => true,
    ]);

    $playlist = Playlist::firstOrCreate(['name' => 'All songs', 'user_id' => $this->user->id]);
    $playlist->files()->syncWithoutDetaching(File::pluck('id')->toArray());

    $response = $this->actingAs($this->user)->get(route('playlists.show', ['playlist' => $playlist->id]));

    $response->assertStatus(200)
        ->assertInertia(fn (Assert $page) => $page
            ->component('audio/Index')
            ->where('files', function ($files) {
                // Should only have 1 file (the non-blacklisted, found one)
                return count($files) === 1;
            })
        );
});

test('playlist page filters non audio files', function () {
    // Create audio file
    File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    // Create non-audio files
    File::factory()->create([
        'mime_type' => 'image/jpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    File::factory()->create([
        'mime_type' => 'video/mp4',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    $playlist = Playlist::firstOrCreate(['name' => 'All songs', 'user_id' => $this->user->id]);
    $playlist->files()->syncWithoutDetaching(File::pluck('id')->toArray());

    $response = $this->actingAs($this->user)->get(route('playlists.show', ['playlist' => $playlist->id]));

    $response->assertStatus(200)
        ->assertInertia(fn (Assert $page) => $page
            ->component('audio/Index')
            ->where('files', function ($files) {
                // Should only have 1 audio file
                return count($files) === 1;
            })
        );
});

test('playlist page preserves query parameter', function () {
    $playlist = Playlist::firstOrCreate(['name' => 'All songs', 'user_id' => $this->user->id]);

    $response = $this->actingAs($this->user)
        ->get(route('playlists.show', ['playlist' => $playlist->id, 'query' => 'test search']));

    $response->assertStatus(200)
        ->assertInertia(fn (Assert $page) => $page
            ->component('audio/Index')
            ->has('files')
            ->has('search')
            ->where('query', 'test search')
        );
});

test('cover model includes temporary url', function () {
    $file = File::factory()->create([
        'mime_type' => 'audio/mpeg',
    ]);

    // Create a cover
    $cover = $file->covers()->create([
        'path' => 'covers/test-cover.jpg',
        'hash' => 'test-hash',
    ]);

    // Refresh to get accessor
    $cover->refresh();

    // Should have URL accessor
    expect($cover->url)->toBeString();
    // Local temporary URLs include signed query params
    expect($cover->url)->toContain('expires=');
    expect($cover->url)->toContain('signature=');

    // When serialized to array/json it should include url
    $array = $cover->toArray();
    expect($array)->toHaveKey('url');
    expect($array['url'])->toBeString();
});
