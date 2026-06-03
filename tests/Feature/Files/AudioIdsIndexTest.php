<?php

use App\Models\Album;
use App\Models\AlbumCover;
use App\Models\Artist;
use App\Models\AudioTrackStat;
use App\Models\Container;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use App\Support\AtlasStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('authenticated user can fetch cursor-paginated audio ids only', function () {
    $user = User::factory()->create();
    $queries = [];

    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $audioOne = File::factory()->create(['mime_type' => 'audio/mpeg', 'source' => 'Spotify', 'source_id' => '1A2B3C4D5E6F7G8H9I0J1K']);
    File::factory()->create(['mime_type' => 'image/jpeg']);
    $audioTwo = File::factory()->create(['mime_type' => 'audio/ogg']);
    $audioThree = File::factory()->create(['mime_type' => 'audio/wav']);

    $response = $this->actingAs($user)->getJson('/api/audio/ids?after_id=0&per_page=2');

    $response->assertSuccessful();
    $response->assertJson([
        'ids' => [$audioOne->id, $audioTwo->id],
        'cursor' => [
            'after_id' => 0,
            'next_after_id' => $audioTwo->id,
            'has_more' => true,
            'max_id' => $audioThree->id,
        ],
        'pagination' => [
            'per_page' => 2,
            'total' => 3,
            'total_pages' => 2,
        ],
    ]);
    $response->assertJsonPath('sources.'.$audioOne->id, $audioOne->source);
    $response->assertJsonPath('sources.'.$audioTwo->id, $audioTwo->source);
    $response->assertJsonPath('source_ids.'.$audioOne->id, '1A2B3C4D5E6F7G8H9I0J1K');
    $response->assertJsonPath('spotify_uris.'.$audioOne->id, 'spotify:track:1A2B3C4D5E6F7G8H9I0J1K');
    expect($queries)->toContainAudioIdPageIndexHint();

    $cursor = $response->json('cursor');

    $nextChunk = $this->actingAs($user)->getJson('/api/audio/ids?after_id='.$cursor['next_after_id'].'&max_id='.$cursor['max_id'].'&per_page=2');
    $nextChunk->assertSuccessful();
    $nextChunk->assertJson([
        'ids' => [$audioThree->id],
        'cursor' => [
            'after_id' => $audioTwo->id,
            'next_after_id' => null,
            'has_more' => false,
            'max_id' => $audioThree->id,
        ],
        'pagination' => [
            'per_page' => 2,
            'total' => null,
            'total_pages' => null,
        ],
    ]);
    $nextChunk->assertJsonPath('sources.'.$audioThree->id, $audioThree->source);
});

expect()->extend('toContainAudioIdPageIndexHint', function () {
    $containsIndexHint = collect($this->value)->contains(
        static fn (string $sql): bool => str_contains($sql, 'indexed by files_mime_type_id_index')
            || str_contains($sql, 'force index (files_mime_type_id_index)')
    );

    expect($containsIndexHint)->toBeTrue();
});

test('guest cannot fetch audio ids', function () {
    $response = $this->getJson('/api/audio/ids');

    $response->assertUnauthorized();
});

test('authenticated user can fetch audio details batch for ids', function () {
    $user = User::factory()->create();

    $audio = File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'title' => null,
        'filename' => 'fallback-track.mp3',
        'source' => 'Spotify',
        'source_id' => '1A2B3C4D5E6F7G8H9I0J1K',
        'poster_path' => 'imports/ab/cd/poster.jpg',
        'previewed_count' => 12,
        'seen_count' => 4,
    ]);
    $audio->metadata()->create([
        'payload' => [
            'title' => 'Song Title',
            'artist' => ['Artist A', 'Artist B'],
            'album' => 'Album Name',
            'duration_seconds' => 215,
        ],
    ]);
    Reaction::query()->create([
        'file_id' => $audio->id,
        'user_id' => $user->id,
        'type' => 'like',
    ]);
    AudioTrackStat::query()->create([
        'file_id' => $audio->id,
        'play_count' => 3,
        'skip_count' => 1,
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->postJson('/api/audio/details', [
        'ids' => [$audio->id],
    ]);

    $response->assertSuccessful();
    $response->assertJson([
        'items' => [
            [
                'id' => $audio->id,
                'title' => 'Song Title',
                'source' => 'Spotify',
                'source_id' => '1A2B3C4D5E6F7G8H9I0J1K',
                'spotify_uri' => 'spotify:track:1A2B3C4D5E6F7G8H9I0J1K',
                'artists' => ['Artist A', 'Artist B'],
                'albums' => ['Album Name'],
                'cover_url' => "/api/files/{$audio->id}/poster",
                'duration_seconds' => 215,
                'reaction' => ['type' => 'like'],
                'blacklisted_at' => null,
                'previewed_count' => 12,
                'seen_count' => 4,
                'play_count' => 3,
                'skip_count' => 1,
            ],
        ],
    ]);
});

test('audio details prefer catalog relationships and album cover over metadata fallback', function () {
    configureLibraryScanStorage();

    $user = User::factory()->create();
    $audio = File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'title' => 'Relationship Track',
        'filename' => 'relationship-track.mp3',
        'poster_path' => 'imports/ab/cd/poster.jpg',
    ]);
    $audio->metadata()->create([
        'payload' => [
            'artist' => 'Metadata Artist',
            'album' => 'Metadata Album',
        ],
    ]);

    $artist = Artist::factory()->create([
        'name' => 'Catalog Artist',
        'normalized_name' => 'catalog artist',
    ]);
    $album = Album::factory()->create([
        'name' => 'Catalog Album',
        'normalized_name' => 'catalog album',
    ]);
    $audio->artists()->attach($artist->id);
    $audio->albums()->attach($album->id);

    $coverPath = 'imports/ab/cd/covers/catalog-cover.jpg';
    Storage::disk(AtlasStorage::DISK)->put($coverPath, 'cover');
    $cover = AlbumCover::query()->create([
        'album_id' => $album->id,
        'file_id' => $audio->id,
        'path' => $coverPath,
        'path_hash' => hash('sha256', $coverPath),
        'mime_type' => 'image/jpeg',
        'is_default' => true,
    ]);

    $response = $this->actingAs($user)->postJson('/api/audio/details', [
        'ids' => [$audio->id],
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('items.0.artists', ['Catalog Artist']);
    $response->assertJsonPath('items.0.albums', ['Catalog Album']);
    $response->assertJsonPath('items.0.cover_url', "/api/audio/album-covers/{$cover->id}");

    $this->actingAs($user)
        ->get("/api/audio/album-covers/{$cover->id}")
        ->assertSuccessful()
        ->assertHeader('content-type', 'image/jpeg');
});

test('audio details prefer canonical title over embedded metadata alias', function () {
    $user = User::factory()->create();
    $audio = File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'title' => 'Canonical Source Title',
        'filename' => 'imported-title.mp3',
    ]);
    $audio->metadata()->create([
        'payload' => [
            'title' => 'Imported Custom Title',
            'audio' => [
                'aliases' => [
                    'title' => ['Imported Custom Title'],
                ],
            ],
        ],
    ]);

    $response = $this->actingAs($user)->postJson('/api/audio/details', [
        'ids' => [$audio->id],
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('items.0.title', 'Canonical Source Title');
});

test('audio details use spotify listing metadata names before container ids', function () {
    $user = User::factory()->create();
    $audio = File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'title' => 'Alt+F4 - Original Mix',
        'source' => 'spotify',
        'source_id' => '5LdKWiOanFSkxKoKir2cXJ',
        'listing_metadata' => [
            'track' => [
                'album' => ['name' => 'Anjunabeats The Early Years 01'],
                'artists' => [['name' => 'Alt+F4']],
            ],
        ],
    ]);
    $audio->containers()->attach([
        Container::query()->create(['type' => 'album', 'source' => 'spotify', 'source_id' => '7egs9GqiVqyD3xoL3KCuZq'])->id,
        Container::query()->create(['type' => 'artist', 'source' => 'spotify', 'source_id' => '2lCj5AjyIK0X3d42EozytS'])->id,
    ]);

    $response = $this->actingAs($user)->postJson('/api/audio/details', [
        'ids' => [$audio->id],
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('items.0.artists', ['Alt+F4']);
    $response->assertJsonPath('items.0.albums', ['Anjunabeats The Early Years 01']);
});

test('guest cannot fetch audio details', function () {
    $response = $this->postJson('/api/audio/details', [
        'ids' => [1],
    ]);

    $response->assertUnauthorized();
});
