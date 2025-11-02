<?php

use App\Models\File;
use App\Models\FileMetadata;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Browser: clicking the cover scrolls the current track into view.
 */
test('clicking cover scrolls to current track in the list', function () {
    Storage::fake('atlas');

    // Create a user first and its All songs playlist
    $user = User::factory()->create();
    $this->actingAs($user);
    $playlist = Playlist::firstOrCreate(['name' => 'All songs', 'user_id' => $user->id]);

    // Create enough files to require scrolling; make sure first is visible and we will use that as current
    $files = File::factory()
        ->count(60)
        ->sequence(fn ($seq) => [
            'filename' => 't'.($seq->index + 1).'.mp3',
            'path' => 'media/t'.($seq->index + 1).'.mp3',
            'mime_type' => 'audio/mpeg',
            'not_found' => false,
            'blacklisted_at' => null,
        ])
        ->has(
            FileMetadata::factory()->state(function (array $attrs, File $file) {
                // derive N from filename so metadata matches the parent row
                $n = (int) preg_replace('/\D+/', '', $file->filename);

                return ['payload' => ['title' => "Track {$n}", 'artist' => "Artist {$n}"]];
            }),
            'metadata' // relation name on File
        )
        ->create();

    // Create fake storage files for each file record
    foreach ($files as $file) {
        Storage::disk('atlas')->put($file->path, 'fake-audio-content');
    }

    // Attach files to playlist
    $playlist->files()->syncWithoutDetaching(File::pluck('id')->toArray());

    $page = visit(route('playlists.show', ['playlist' => $playlist->id]))
        ->assertNoSmoke()
        ->assertSee('Audio Library');

    // Play the first visible row to ensure global player shows
    $page->click('Play')->assertNoSmoke();

    // Scroll the virtual scroller to the bottom to hide the current item from view
    $page->script('
      const sc = document.querySelector("[data-testid=\"audio-scroller\"]");
      if (sc) sc.scrollTop = sc.scrollHeight;
    ');

    // Trigger scroll-to-current via the album cover control in the player
    $page->click('Show current in list')->assertNoSmoke();

    // A temporary accessible marker is rendered on the item when scrolled to current
    $page->assertSee('Scrolled to current');
});
