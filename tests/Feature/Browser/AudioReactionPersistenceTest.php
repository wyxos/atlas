<?php

use App\Models\File;
use App\Models\FileMetadata;
use App\Models\Playlist;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    useTypesense();
    resetTypesenseFileCollection();
});

/**
 * Browser: toggling reaction on list persists after reload.
 */
test('toggling like on list persists after reload and updates Liked playlist', function () {
    Storage::fake('atlas');

    // Create user and default playlists
    $user = User::factory()->create();
    $this->actingAs($user);

    $all = Playlist::firstOrCreate(['name' => 'All songs', 'user_id' => $user->id]);

    // Seed one playable audio file
    Storage::disk('atlas')->put('media/a1.mp3', 'bytes1');
    $file = File::factory()->create([
        'filename' => 'song-a.mp3',
        'path' => 'media/a1.mp3',
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);
    FileMetadata::create(['file_id' => $file->id, 'payload' => ['title' => 'Track A', 'artist' => 'Artist A']]);

    // Attach to All songs
    $all->files()->syncWithoutDetaching([$file->id]);

    // Reindex in Typesense
    $file->searchable();

    // Visit All songs and click Like
    $page = visit(route('playlists.show', ['playlist' => $all->id]))
        ->assertNoSmoke()
        ->assertSee('Audio Library');

    $page->click('Like')->assertNoSmoke();

    // DB should reflect reaction row (allow brief async delay)
    $r = null;
    $deadline = microtime(true) + 2.0; // wait up to 2 seconds
    do {
        $r = Reaction::where('file_id', $file->id)->where('user_id', $user->id)->first();
        if ($r) {
            break;
        }
        usleep(50_000);
    } while (microtime(true) < $deadline);
    expect($r)->not->toBeNull()->and($r->type)->toBe('like');

    // Reindex the file with the new reaction
    $file->refresh()->searchable();

    // Liked page (/audio/liked) should show the file (not empty)
    visit(route('audio.reactions', ['type' => 'liked']))
        ->assertNoSmoke()
        ->assertSee('Audio Library')
        ->assertDontSee('No audio files found');

    // Reload the page and ensure it's still present
    visit(route('audio.reactions', ['type' => 'liked']))
        ->assertNoSmoke()
        ->assertSee('Audio Library')
        ->assertDontSee('No audio files found');
});
