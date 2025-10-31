<?php

use App\Models\File;
use App\Models\FileMetadata;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Browser: playing from Audio list marks "All songs" as active playlist for the user.
 */
test('playing from audio list activates All songs playlist for the user', function () {
    Storage::fake('atlas');

    $user = User::factory()->create();
    $this->actingAs($user);
    $playlist = Playlist::firstOrCreate(['name' => 'All songs', 'user_id' => $user->id]);

    Storage::disk('atlas')->put('media/a1.mp3', 'bytes1');
    Storage::disk('atlas')->put('media/a2.mp3', 'bytes2');

    $f1 = File::factory()->create([
        'filename' => 'song-a.mp3',
        'path' => 'media/a1.mp3',
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);
    $f2 = File::factory()->create([
        'filename' => 'song-b.mp3',
        'path' => 'media/a2.mp3',
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    FileMetadata::create(['file_id' => $f1->id, 'payload' => ['title' => 'Track A', 'artist' => 'Artist A']]);
    FileMetadata::create(['file_id' => $f2->id, 'payload' => ['title' => 'Track B', 'artist' => 'Artist B']]);

    // Attach to playlist
    $playlist->files()->syncWithoutDetaching([$f1->id, $f2->id]);

    $page = visit(route('playlists.show', ['playlist' => $playlist->id]))
        ->assertNoSmoke()
        ->assertSee('Audio Library');

    // Play the first row
    $page->click('Play');

    // Assert user's active playlist is set to their All songs playlist
    $playlistId = Playlist::where('user_id', $user->id)->where('name', 'All songs')->value('id');
    $user->refresh();
    expect($user->active_playlist_id)->toBe($playlistId);
});
