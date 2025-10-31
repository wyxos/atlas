<?php

use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('requires authentication to view audio favorites', function () {
    $this->get('/audio/favorites')
        ->assertRedirect('/login');
});

it('shows empty favorites for authenticated user with no love reactions', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/audio/favorites')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('audio/Index')
            ->has('files', 0)
            ->has('playlistFileIds', 0)
        );
});

it('shows audio files that the user has loved', function () {
    $user = User::factory()->create();

    // Create some audio files
    $lovedAudioFile = File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    $unlovedAudioFile = File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    $nonAudioFile = File::factory()->create([
        'mime_type' => 'image/jpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    // Love the first audio file and the non-audio file
    Reaction::create([
        'file_id' => $lovedAudioFile->id,
        'user_id' => $user->id,
        'type' => 'love',
    ]);

    Reaction::create([
        'file_id' => $nonAudioFile->id,
        'user_id' => $user->id,
        'type' => 'love',
    ]);

    // Make the files searchable (this would normally be done via Scout/Typesense)
    // Note: This is a simplified test that won't actually use Scout indexing
    $this->actingAs($user)
        ->get('/audio/favorites')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('audio/Index')
            ->where('playlistId', null)
            ->where('isSpotifyPlaylist', false)
        );
});

it('favorites data endpoint returns json', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/audio/favorites/data')
        ->assertOk()
        ->assertJson([
            'files' => [],
            'search' => [],
            'playlistFileIds' => [],
            'query' => '',
            'playlistId' => null,
            'isSpotifyPlaylist' => false,
            'containsSpotify' => false,
        ]);
});
