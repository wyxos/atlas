<?php

use App\Models\File;
use App\Models\FileMetadata;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/****
 * Browser: basic play flow creates queue and updates global player text
 */

test('clicking play updates global player with selected track', function () {
    Storage::fake('atlas');

    // Create user so the All songs playlist can be created by the observer
    $user = User::factory()->create();
    $this->actingAs($user);

    // Create All songs playlist for user
    $playlist = Playlist::firstOrCreate(['name' => 'All songs', 'user_id' => $user->id]);

    // Seed two playable audio files
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

    // Attach files to playlist
    $playlist->files()->syncWithoutDetaching([$f1->id, $f2->id]);

    $page = visit(route('playlists.show', ['playlist' => $playlist->id]))
        ->assertNoSmoke()
        ->assertSee('Audio Library')
        ->assertSee('tracks');

    // Click the first play button; the button has an accessible label
    $page->click('Play')
        ->assertSee('Track A');
});
