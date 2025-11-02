<?php

use App\Models\File;
use App\Models\FileMetadata;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Browser: clicking an item sets queue to the full playlist and next/prev navigates within the playlist order
 */
test('clicking playlist item queues full playlist and next/previous navigates playlist order', function () {
    Storage::fake('atlas');

    // Create user to allow playlist observer to attach
    $user = User::factory()->create();
    $this->actingAs($user);

    // Create All songs playlist for user
    $playlist = Playlist::firstOrCreate(['name' => 'All songs', 'user_id' => $user->id]);

    // Seed three playable audio files with covers/metadata
    Storage::disk('atlas')->put('media/a1.mp3', 'bytes1');
    Storage::disk('atlas')->put('media/a2.mp3', 'bytes2');
    Storage::disk('atlas')->put('media/a3.mp3', 'bytes3');

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
    $f3 = File::factory()->create([
        'filename' => 'song-c.mp3',
        'path' => 'media/a3.mp3',
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    FileMetadata::create(['file_id' => $f1->id, 'payload' => ['title' => 'Track A']]);
    FileMetadata::create(['file_id' => $f2->id, 'payload' => ['title' => 'Track B']]);
    FileMetadata::create(['file_id' => $f3->id, 'payload' => ['title' => 'Track C']]);

    // Attach files to playlist
    $playlist->files()->syncWithoutDetaching([$f1->id, $f2->id, $f3->id]);

    $page = visit(route('playlists.show', ['playlist' => $playlist->id]))
        ->assertNoSmoke()
        ->assertSee('Audio Library')
        ->assertSee('tracks');

    // Click play on the second visible row (should queue full playlist and start at B)
    $page->click('Play') // first click starts the first visible row (Track A) by default
        ->assertSee('Track A');

    // Click Next should go to Track B, then Track C, then stop
    $page->click('Next')->assertSee('Track B');
    $page->click('Next')->assertSee('Track C');
    // Previous returns to Track B
    $page->click('Previous')->assertSee('Track B');
});
