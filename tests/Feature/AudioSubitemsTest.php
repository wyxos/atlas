<?php

use App\Models\File;
use App\Models\User;
use App\Models\Artist;
use App\Models\Album;
use App\Models\Playlist;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('audio favorites page can be accessed', function () {
    $response = $this->actingAs($this->user)->get('/audio/favorites');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Audio')
        ->has('files')
        ->where('title', 'Favorites')
    );
});

test('audio liked page can be accessed', function () {
    $response = $this->actingAs($this->user)->get('/audio/liked');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Audio')
        ->has('files')
        ->where('title', 'Liked')
    );
});

test('audio disliked page can be accessed', function () {
    $response = $this->actingAs($this->user)->get('/audio/disliked');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Audio')
        ->has('files')
        ->where('title', 'Disliked')
    );
});

test('artists page can be accessed', function () {
    $response = $this->actingAs($this->user)->get('/artists');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Artists')
        ->has('artists')
    );
});

test('albums page can be accessed', function () {
    $response = $this->actingAs($this->user)->get('/albums');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Albums')
        ->has('albums')
    );
});

test('playlists page can be accessed', function () {
    $response = $this->actingAs($this->user)->get('/playlists');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Playlists')
        ->has('playlists')
    );
});

test('audio unrated page can be accessed', function () {
    $response = $this->actingAs($this->user)->get('/audio/unrated');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Audio')
        ->has('files')
        ->where('title', 'Unrated')
    );
});

test('favorites page shows only loved audio files', function () {
    // Create audio files with different statuses
    $lovedFile = File::factory()->create([
        'mime_type' => 'audio/mp3',
        'loved' => true,
        'not_found' => false,
    ]);

    $likedFile = File::factory()->create([
        'mime_type' => 'audio/mp3',
        'liked' => true,
        'not_found' => false,
    ]);

    $normalFile = File::factory()->create([
        'mime_type' => 'audio/mp3',
        'not_found' => false,
    ]);

    $response = $this->actingAs($this->user)->get('/audio/favorites');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Audio')
        ->where('files', function ($files) use ($lovedFile) {
            return collect($files)->contains('id', $lovedFile->id);
        })
        ->where('title', 'Favorites')
    );
});

test('liked page shows only liked audio files', function () {
    // Create audio files with different statuses
    $likedFile = File::factory()->create([
        'mime_type' => 'audio/mp3',
        'liked' => true,
        'not_found' => false,
    ]);

    $lovedFile = File::factory()->create([
        'mime_type' => 'audio/mp3',
        'loved' => true,
        'not_found' => false,
    ]);

    $response = $this->actingAs($this->user)->get('/audio/liked');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Audio')
        ->where('files', function ($files) use ($likedFile) {
            return collect($files)->contains('id', $likedFile->id);
        })
        ->where('title', 'Liked')
    );
});

test('disliked page shows only disliked audio files', function () {
    // Create audio files with different statuses
    $dislikedFile = File::factory()->create([
        'mime_type' => 'audio/mp3',
        'disliked' => true,
        'not_found' => false,
    ]);

    $normalFile = File::factory()->create([
        'mime_type' => 'audio/mp3',
        'not_found' => false,
    ]);

    $response = $this->actingAs($this->user)->get('/audio/disliked');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Audio')
        ->where('files', function ($files) use ($dislikedFile) {
            return collect($files)->contains('id', $dislikedFile->id);
        })
        ->where('title', 'Disliked')
    );
});

test('unrated page shows only unrated audio files', function () {
    // Create audio files with different statuses
    $unratedFile1 = File::factory()->create([
        'mime_type' => 'audio/mp3',
        'loved' => false,
        'liked' => false,
        'disliked' => false,
        'funny' => false,
        'not_found' => false,
    ]);

    $unratedFile2 = File::factory()->create([
        'mime_type' => 'audio/mp3',
        'loved' => false,
        'liked' => false,
        'disliked' => false,
        'funny' => false,
        'not_found' => false,
    ]);

    $lovedFile = File::factory()->create([
        'mime_type' => 'audio/mp3',
        'loved' => true,
        'not_found' => false,
    ]);

    $likedFile = File::factory()->create([
        'mime_type' => 'audio/mp3',
        'liked' => true,
        'not_found' => false,
    ]);

    $dislikedFile = File::factory()->create([
        'mime_type' => 'audio/mp3',
        'disliked' => true,
        'not_found' => false,
    ]);

    $funnyFile = File::factory()->create([
        'mime_type' => 'audio/mp3',
        'funny' => true,
        'not_found' => false,
    ]);

    $response = $this->actingAs($this->user)->get('/audio/unrated');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Audio')
        ->where('files', function ($files) use ($unratedFile1, $unratedFile2, $lovedFile, $likedFile, $dislikedFile, $funnyFile) {
            $fileIds = collect($files)->pluck('id');
            return $fileIds->contains($unratedFile1->id) &&
                   $fileIds->contains($unratedFile2->id) &&
                   !$fileIds->contains($lovedFile->id) &&
                   !$fileIds->contains($likedFile->id) &&
                   !$fileIds->contains($dislikedFile->id) &&
                   !$fileIds->contains($funnyFile->id);
        })
        ->where('title', 'Unrated')
    );
});

test('playlists are shared globally for sidebar', function () {
    // Create some playlists for the user
    $playlist1 = Playlist::factory()->create([
        'name' => 'My Rock Playlist',
        'user_id' => $this->user->id,
    ]);

    $playlist2 = Playlist::factory()->create([
        'name' => 'Jazz Collection',
        'user_id' => $this->user->id,
    ]);

    // Create a playlist for another user (should not be included)
    $otherUser = User::factory()->create();
    $otherPlaylist = Playlist::factory()->create([
        'name' => 'Other User Playlist',
        'user_id' => $otherUser->id,
    ]);

    $response = $this->actingAs($this->user)->get('/dashboard');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->has('playlists', 2)
        ->where('playlists.0.name', 'Jazz Collection') // Ordered by name
        ->where('playlists.0.id', $playlist2->id)
        ->where('playlists.1.name', 'My Rock Playlist')
        ->where('playlists.1.id', $playlist1->id)
    );
});
