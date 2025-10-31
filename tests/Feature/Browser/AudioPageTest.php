<?php

use App\Models\File;
use App\Models\FileMetadata;
use App\Models\Playlist;
use App\Models\User;

beforeEach(function () {
    useTypesense();
    resetTypesenseFileCollection();
    \App\Models\File::search('warmup')->get();
});

test('audio page renders and shows tracks count', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $playlist = Playlist::firstOrCreate(['name' => 'All songs', 'user_id' => $user->id]);

    File::factory()->count(5)->create([
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    // Ensure index reflects current data
    File::query()->searchable();

    // Attach all audio files to All songs playlist
    $ids = File::pluck('id')->toArray();
    $playlist->files()->syncWithoutDetaching($ids);

    $response = visit(route('playlists.show', ['playlist' => $playlist->id]));
    $response->assertNoSmoke();
    $response->assertSee('Audio Library');
    $response->assertSee('tracks');
});

test('audio search uses Typesense and preserves query in the URL', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $match = File::factory()->create([
        'filename' => 'power_rangers_theme.mp3',
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    $other = File::factory()->create([
        'filename' => 'another_song.mp3',
        'title' => 'Another Song',
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    // Enrich with metadata
    FileMetadata::create([
        'file_id' => $match->id,
        'payload' => [
            'title' => 'Power Rangers Theme Song',
            'artist' => 'Ron Wasserman',
        ],
    ]);

    $playlist = Playlist::firstOrCreate(['name' => 'All songs', 'user_id' => $user->id]);

    // Attach to playlist then index
    $playlist->files()->syncWithoutDetaching([$match->id, $other->id]);

    // Index into Typesense
    File::query()->searchable();

    $response = visit(route('playlists.show', ['playlist' => $playlist->id, 'query' => 'power rangers']));
    $response->assertSee('Audio Library');
    // Should show the match
    $response->assertSee('Power Rangers Theme Song');
});

test('when search has no matches, an empty state is shown instead of playlist content', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Seed some playlist audio
    File::factory()->create([
        'filename' => 'list-a.mp3',
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    $playlist = Playlist::firstOrCreate(['name' => 'All songs', 'user_id' => $user->id]);
    $playlist->files()->syncWithoutDetaching(File::pluck('id')->toArray());

    // Index into Typesense
    File::query()->searchable();

    $response = visit(route('playlists.show', ['playlist' => $playlist->id]).'?query=nonexistentsearch');
    $response->assertNoSmoke();
    $response->assertSee('Audio Library');
    // Should show an explicit empty state
    $response->assertSee('No results found');
});
