<?php

use App\Models\Album;
use App\Models\AlbumCover;
use App\Models\Artist;
use App\Models\File;
use App\Models\Playlist;
use App\Models\Reaction;
use App\Models\User;
use App\Services\FilePreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('audio playlists endpoint syncs system playlists and dynamic sources', function () {
    $user = User::factory()->create();
    $spotify = File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'poster_path' => 'imports/ab/cd/poster.jpg',
        'source' => 'Spotify',
    ]);
    File::factory()->create([
        'mime_type' => 'audio/ogg',
        'source' => 'Bandcamp',
        'imported_at' => now(),
    ]);
    File::factory()->create([
        'mime_type' => 'audio/wav',
        'source' => 'NAS',
    ]);
    File::factory()->create([
        'mime_type' => 'image/jpeg',
        'source' => 'Bandcamp',
    ]);
    Reaction::query()->create([
        'file_id' => $spotify->id,
        'user_id' => $user->id,
        'type' => 'love',
    ]);
    Playlist::query()->create([
        'user_id' => $user->id,
        'slug' => 'out-of-feed',
        'name' => 'Out of feed',
        'kind' => 'system',
        'membership_mode' => 'rules',
        'membership_rules' => ['operator' => 'blacklisted'],
        'is_system' => true,
        'is_editable' => false,
        'is_deletable' => false,
    ]);
    Playlist::query()->create([
        'user_id' => $user->id,
        'slug' => null,
        'name' => 'Not Found',
        'kind' => 'system',
        'membership_mode' => 'rules',
        'membership_rules' => ['operator' => 'missing'],
        'is_system' => true,
        'is_smart' => true,
        'is_editable' => false,
        'is_deletable' => false,
    ]);

    $response = $this->actingAs($user)->getJson('/api/audio/playlists');

    $response->assertSuccessful();
    $response->assertJsonPath('sections.0.key', 'system');
    $response->assertJsonPath('sections.0.label', 'System');
    expect(Playlist::query()->where('user_id', $user->id)->where('slug', 'source-bandcamp')->exists())->toBeTrue();

    $playlists = collect($response->json('sections.0.playlists'))->keyBy('slug');
    expect($playlists->get('all')['count'])->toBe(3)
        ->and($playlists->get('all')['cover_mode'])->toBe('first_track')
        ->and($playlists->get('all')['cover_file_id'])->toBe($spotify->id)
        ->and($playlists->get('all')['cover_file_ids'])->toBe([])
        ->and($playlists->get('all')['cover_url'])->toBe("/api/files/{$spotify->id}/poster")
        ->and($playlists->get('favorites')['count'])->toBe(1)
        ->and($playlists->get('favorites-and-likes')['name'])->toBe('Favorites & Likes')
        ->and($playlists->get('favorites-and-likes')['count'])->toBe(1)
        ->and($playlists->get('reacted')['name'])->toBe('Reacted')
        ->and($playlists->get('reacted')['count'])->toBe(1)
        ->and($playlists->get('banned')['name'])->toBe('Banned')
        ->and($playlists->get('unreacted')['name'])->toBe('Unreacted')
        ->and($playlists->get('unreacted')['count'])->toBe(2)
        ->and($playlists->has('out-of-feed'))->toBeFalse()
        ->and($playlists->contains(fn (array $playlist): bool => $playlist['name'] === 'Not Found'))->toBeFalse()
        ->and($playlists->has('source-nas'))->toBeFalse()
        ->and($playlists->has('source-local'))->toBeFalse()
        ->and(Playlist::query()->where('user_id', $user->id)->where('slug', 'out-of-feed')->exists())->toBeFalse()
        ->and(Playlist::query()->where('user_id', $user->id)->where('name', 'Not Found')->whereNull('slug')->exists())->toBeFalse()
        ->and($playlists->get('imports')['count'])->toBe(2)
        ->and($playlists->get('online-sources')['name'])->toBe('Online sources')
        ->and($playlists->get('online-sources')['count'])->toBe(1)
        ->and($playlists->get('no-artist')['name'])->toBe('No artist')
        ->and($playlists->get('no-artist')['count'])->toBe(2)
        ->and($playlists->get('no-album')['name'])->toBe('No album')
        ->and($playlists->get('no-album')['count'])->toBe(2)
        ->and($playlists->get('no-album-cover')['name'])->toBe('No album cover')
        ->and($playlists->get('no-album-cover')['count'])->toBe(0)
        ->and($playlists->get('source-spotify')['count'])->toBe(1)
        ->and($playlists->get('source-bandcamp')['name'])->toBe('Bandcamp')
        ->and($playlists->get('source-bandcamp')['is_deletable'])->toBeFalse();
});

test('audio playlists resolve first track and random track covers', function () {
    $user = User::factory()->create();
    $lateTrack = File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'poster_path' => 'imports/ab/cd/late.jpg',
    ]);
    $firstTrack = File::factory()->create([
        'mime_type' => 'audio/ogg',
        'poster_path' => 'imports/ab/cd/first.jpg',
    ]);
    $outsideTrack = File::factory()->create([
        'mime_type' => 'audio/wav',
        'poster_path' => 'imports/ab/cd/outside.jpg',
    ]);

    $playlist = Playlist::query()->create([
        'user_id' => $user->id,
        'slug' => 'manual-set',
        'name' => 'Manual set',
        'kind' => 'manual',
        'membership_mode' => 'manual',
        'is_system' => false,
        'is_smart' => false,
        'is_editable' => true,
        'is_deletable' => true,
        'cover_mode' => 'first_track',
    ]);
    $playlist->files()->attach($lateTrack->id, ['position' => 2]);
    $playlist->files()->attach($firstTrack->id, ['position' => 1]);

    $response = $this->actingAs($user)->getJson('/api/audio/playlists');

    $response->assertSuccessful();
    $manualPlaylist = collect($response->json('sections.2.playlists'))->firstWhere('slug', 'manual-set');
    expect($manualPlaylist['cover_file_id'])->toBe($firstTrack->id)
        ->and($manualPlaylist['cover_url'])->toBe("/api/files/{$firstTrack->id}/poster");

    $playlist->update(['cover_mode' => 'random_track']);

    $response = $this->actingAs($user)->getJson('/api/audio/playlists');

    $response->assertSuccessful();
    $manualPlaylist = collect($response->json('sections.2.playlists'))->firstWhere('slug', 'manual-set');
    expect([$lateTrack->id, $firstTrack->id])->toContain($manualPlaylist['cover_file_id'])
        ->and($manualPlaylist['cover_file_id'])->not->toBe($outsideTrack->id);
});

test('playlist cover update supports custom covers for manual smart and system playlists and preserves system covers across sync', function () {
    $user = User::factory()->create();
    $coverOne = File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'poster_path' => 'imports/ab/cd/custom-one.jpg',
        'source' => 'Spotify',
    ]);
    $coverTwo = File::factory()->create([
        'mime_type' => 'audio/ogg',
        'poster_path' => 'imports/ab/cd/custom-two.jpg',
        'source' => 'Spotify',
    ]);
    Reaction::query()->create([
        'file_id' => $coverOne->id,
        'user_id' => $user->id,
        'type' => 'love',
    ]);
    $manual = Playlist::query()->create([
        'user_id' => $user->id,
        'slug' => 'manual-set',
        'name' => 'Manual set',
        'kind' => 'manual',
        'membership_mode' => 'manual',
        'is_system' => false,
        'is_smart' => false,
        'is_editable' => true,
        'is_deletable' => true,
    ]);
    $smart = Playlist::query()->create([
        'user_id' => $user->id,
        'slug' => 'smart-spotify',
        'name' => 'Smart Spotify',
        'kind' => 'smart',
        'membership_mode' => 'rules',
        'membership_rules' => ['operator' => 'source', 'source_key' => 'spotify'],
        'is_system' => false,
        'is_smart' => true,
        'is_editable' => true,
        'is_deletable' => true,
    ]);

    foreach ([$manual, $smart] as $playlist) {
        $this->actingAs($user)->patchJson("/api/audio/playlists/{$playlist->id}/cover", [
            'cover_file_ids' => [$coverOne->id],
            'cover_mode' => 'custom',
        ])
            ->assertSuccessful()
            ->assertJsonPath('playlist.cover_mode', 'custom')
            ->assertJsonPath('playlist.cover_file_ids', [$coverOne->id])
            ->assertJsonPath('playlist.cover_file_id', $coverOne->id);
    }

    $this->actingAs($user)->getJson('/api/audio/playlists')->assertSuccessful();
    $favorites = Playlist::query()
        ->where('user_id', $user->id)
        ->where('slug', 'favorites')
        ->firstOrFail();

    $update = $this->actingAs($user)->patchJson("/api/audio/playlists/{$favorites->id}/cover", [
        'cover_file_ids' => [$coverOne->id, $coverTwo->id],
        'cover_mode' => 'custom',
    ]);

    $update->assertSuccessful();
    $update->assertJsonPath('playlist.cover_mode', 'custom');
    expect($update->json('playlist.cover_file_ids'))->toBe([$coverOne->id, $coverTwo->id])
        ->and([$coverOne->id, $coverTwo->id])->toContain($update->json('playlist.cover_file_id'));

    $response = $this->actingAs($user)->getJson('/api/audio/playlists');

    $response->assertSuccessful();
    $playlists = collect($response->json('sections.0.playlists'))->keyBy('slug');
    expect($playlists->get('favorites')['cover_mode'])->toBe('custom')
        ->and($playlists->get('favorites')['cover_file_ids'])->toBe([$coverOne->id, $coverTwo->id])
        ->and([$coverOne->id, $coverTwo->id])->toContain($playlists->get('favorites')['cover_file_id']);
});

test('playlist cover update rejects invalid modes and non audio covers', function () {
    $user = User::factory()->create();
    $image = File::factory()->create(['mime_type' => 'image/jpeg']);
    $playlist = Playlist::query()->create([
        'user_id' => $user->id,
        'slug' => 'manual-set',
        'name' => 'Manual set',
        'kind' => 'manual',
        'membership_mode' => 'manual',
        'is_system' => false,
        'is_smart' => false,
        'is_editable' => true,
        'is_deletable' => true,
    ]);

    $this->actingAs($user)
        ->patchJson("/api/audio/playlists/{$playlist->id}/cover", [
            'cover_mode' => 'album',
        ])
        ->assertUnprocessable();

    $this->actingAs($user)
        ->patchJson("/api/audio/playlists/{$playlist->id}/cover", [
            'cover_file_ids' => [$image->id],
            'cover_mode' => 'custom',
        ])
        ->assertUnprocessable();
});

test('audio catalog cleanup playlists expose imported relationship gaps', function () {
    $user = User::factory()->create();
    $artist = Artist::factory()->create();
    $coveredAlbum = Album::factory()->create();
    $uncoveredAlbum = Album::factory()->create();

    $complete = File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'source' => 'local',
        'imported_at' => now(),
    ]);
    $missingArtist = File::factory()->create([
        'mime_type' => 'audio/ogg',
        'source' => 'local',
        'imported_at' => now(),
    ]);
    $missingAlbum = File::factory()->create([
        'mime_type' => 'audio/wav',
        'source' => 'local',
        'imported_at' => now(),
    ]);
    $missingCover = File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'source' => 'local',
        'imported_at' => now(),
    ]);
    File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'source' => 'Spotify',
        'imported_at' => null,
    ]);

    $complete->artists()->attach($artist);
    $complete->albums()->attach($coveredAlbum);
    $missingArtist->albums()->attach($coveredAlbum);
    $missingAlbum->artists()->attach($artist);
    $missingCover->artists()->attach($artist);
    $missingCover->albums()->attach($uncoveredAlbum);

    AlbumCover::factory()->create([
        'album_id' => $coveredAlbum->id,
        'file_id' => $complete->id,
        'is_default' => true,
    ]);

    $response = $this->actingAs($user)->getJson('/api/audio/playlists');

    $response->assertSuccessful();
    $playlists = collect($response->json('sections.0.playlists'))->keyBy('slug');
    expect($playlists->get('no-artist')['count'])->toBe(1)
        ->and($playlists->get('no-album')['count'])->toBe(1)
        ->and($playlists->get('no-album-cover')['count'])->toBe(1);

    $this->actingAs($user)
        ->getJson('/api/audio/ids?playlist=no-artist&after_id=0&per_page=10')
        ->assertSuccessful()
        ->assertJsonPath('ids', [$missingArtist->id]);

    $this->actingAs($user)
        ->getJson('/api/audio/ids?playlist=no-album&after_id=0&per_page=10')
        ->assertSuccessful()
        ->assertJsonPath('ids', [$missingAlbum->id]);

    $this->actingAs($user)
        ->getJson('/api/audio/ids?playlist=no-album-cover&after_id=0&per_page=10')
        ->assertSuccessful()
        ->assertJsonPath('ids', [$missingCover->id]);
});

test('audio ids can be filtered by system playlist slug', function () {
    $user = User::factory()->create();
    $favorite = File::factory()->create(['mime_type' => 'audio/mpeg', 'source' => 'Spotify']);
    $liked = File::factory()->create(['mime_type' => 'audio/ogg', 'source' => 'Spotify']);
    $funny = File::factory()->create(['mime_type' => 'audio/flac', 'source' => 'Bandcamp']);
    $removed = File::factory()->create([
        'mime_type' => 'audio/wav',
        'source' => 'local',
        'previewed_count' => FilePreviewService::FEED_REMOVED_PREVIEW_COUNT,
    ]);
    $unreacted = File::factory()->create(['mime_type' => 'audio/mpeg', 'source' => 'Bandcamp']);

    Reaction::query()->create([
        'file_id' => $favorite->id,
        'user_id' => $user->id,
        'type' => 'love',
    ]);
    Reaction::query()->create([
        'file_id' => $liked->id,
        'user_id' => $user->id,
        'type' => 'like',
    ]);
    Reaction::query()->create([
        'file_id' => $funny->id,
        'user_id' => $user->id,
        'type' => 'funny',
    ]);

    $this->actingAs($user)->getJson('/api/audio/playlists')->assertSuccessful();

    $this->actingAs($user)
        ->getJson('/api/audio/ids?playlist=favorites&after_id=0&per_page=10')
        ->assertSuccessful()
        ->assertJsonPath('ids', [$favorite->id]);

    $this->actingAs($user)
        ->getJson('/api/audio/ids?playlist=favorites-and-likes&after_id=0&per_page=10')
        ->assertSuccessful()
        ->assertJsonPath('ids', [$favorite->id, $liked->id]);

    $this->actingAs($user)
        ->getJson('/api/audio/ids?playlist=reacted&after_id=0&per_page=10')
        ->assertSuccessful()
        ->assertJsonPath('ids', [$favorite->id, $liked->id, $funny->id]);

    $this->actingAs($user)
        ->getJson('/api/audio/ids?playlist=banned&after_id=0&per_page=10')
        ->assertSuccessful()
        ->assertJsonPath('ids', [$removed->id]);

    $this->actingAs($user)
        ->getJson('/api/audio/ids?playlist=unreacted&after_id=0&per_page=10')
        ->assertSuccessful()
        ->assertJsonPath('ids', [$removed->id, $unreacted->id]);

    $this->actingAs($user)
        ->getJson('/api/audio/ids?playlist=source-spotify&after_id=0&per_page=10')
        ->assertSuccessful()
        ->assertJsonPath('ids', [$favorite->id, $liked->id]);
});

test('system playlist sync command can backfill existing users', function () {
    $user = User::factory()->create();
    File::factory()->create(['mime_type' => 'audio/mpeg', 'source' => 'Bandcamp']);

    $this->artisan('atlas:sync-system-playlists')
        ->expectsOutputToContain('Synced')
        ->assertExitCode(0);

    expect(Playlist::query()->where('user_id', $user->id)->where('slug', 'all')->exists())->toBeTrue()
        ->and(Playlist::query()->where('user_id', $user->id)->where('slug', 'source-bandcamp')->exists())->toBeTrue();
});
