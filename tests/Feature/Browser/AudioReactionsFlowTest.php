<?php

use App\Models\File;
use App\Models\FileMetadata;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Browser: reacting in the global player updates DB and persists
 */
test('love reaction from global player persists and updates DB', function () {
    Storage::fake('atlas');

    // Create user and All songs playlist
    $user = User::factory()->create();
    $this->actingAs($user);
    $playlist = Playlist::firstOrCreate(['name' => 'All songs', 'user_id' => $user->id]);

    // Seed one playable audio file
    Storage::disk('atlas')->put('media/a1.mp3', 'bytes1');

    $f1 = File::factory()->create([
        'filename' => 'song-a.mp3',
        'path' => 'media/a1.mp3',
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    FileMetadata::create(['file_id' => $f1->id, 'payload' => ['title' => 'Track A', 'artist' => 'Artist A']]);

    // Attach file to playlist
    $playlist->files()->syncWithoutDetaching([$f1->id]);

    $page = visit(route('playlists.show', ['playlist' => $playlist->id]))
        ->assertNoSmoke()
        ->assertSee('Audio Library');

    // Click the first Play button then Love in the global player
    $page->click('Play');

    // Now click the Love control in the global player
    $page->click('Love');

    // DB should reflect loved state via Reaction row
    $r = \App\Models\Reaction::where('file_id', $f1->id)->where('user_id', $user->id)->first();
    expect($r)->not->toBeNull()->and($r->type)->toBe('love');
});
