<?php

use App\Models\File;
use App\Models\User;
use App\Models\Artist;
use App\Models\Album;
use App\Models\Playlist;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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

test('audio artists page can be accessed', function () {
    $response = $this->actingAs($this->user)->get('/audio/artists');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Audio')
        ->has('artists')
        ->where('title', 'Artists')
    );
});

test('audio albums page can be accessed', function () {
    $response = $this->actingAs($this->user)->get('/audio/albums');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Audio')
        ->has('albums')
        ->where('title', 'Albums')
    );
});

test('audio playlists page can be accessed', function () {
    $response = $this->actingAs($this->user)->get('/audio/playlists');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Audio')
        ->has('playlists')
        ->where('title', 'Playlists')
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
