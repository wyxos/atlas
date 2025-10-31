<?php

use App\Models\File;
use App\Models\FileMetadata;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Browser: when a membership change event moves the current track away from the queued playlist,
 * the player removes it from the queue and skips to the next track.
 */
test('membership change event removes current from queue and skips to next', function () {
    Storage::fake('atlas');

    // Ensure broadcasting uses Reverb so the browser receives events
    config()->set('broadcasting.default', 'reverb');

    $user = User::factory()->create();
    $this->actingAs($user);

    // Create Liked & Disliked playlists for this test
    $liked = Playlist::firstOrCreate(['user_id' => $user->id, 'name' => 'Liked']);
    $disliked = Playlist::firstOrCreate(['user_id' => $user->id, 'name' => 'Disliked']);
    $likedId = $liked->id;
    $dislikedId = $disliked->id;

    // Seed two playable audio files in the Liked playlist
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

    $liked->files()->syncWithoutDetaching([$f1->id, $f2->id]);

    // Visit the Liked playlist page and start playback (queue originates from Liked)
    $page = visit(route('playlists.show', ['playlist' => $likedId]))
        ->assertNoSmoke()
        ->assertSee('Audio Library');

    $page->click('Play')->assertSee('Track A');

    // React on the current file to move it to Disliked (triggers broadcast)
    $this->postJson(route('audio.react', ['file' => $f1->id]), ['type' => 'dislike'])->assertOk();

    // The player should have skipped to Track B
    $page->assertSee('Track B');
});
