<?php

use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('returns all audio songs when accessing /audio/all', function () {
    $files = File::factory(3)->create([
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    $response = $this->actingAs($this->user)->get('/audio/all');

    $response->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('audio/Index')
            ->where('playlistId', null)
        );
});

it('returns favorites when accessing /audio/favorites', function () {
    $file = File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    Reaction::create([
        'user_id' => $this->user->id,
        'file_id' => $file->id,
        'type' => 'loved',
    ]);

    $response = $this->actingAs($this->user)->get('/audio/favorites');

    $response->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('audio/Index')
            ->where('playlistId', null)
        );
});

it('returns liked songs when accessing /audio/liked', function () {
    $file = File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    Reaction::create([
        'user_id' => $this->user->id,
        'file_id' => $file->id,
        'type' => 'liked',
    ]);

    $response = $this->actingAs($this->user)->get('/audio/liked');

    $response->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('audio/Index')
            ->where('playlistId', null)
        );
});

it('returns funny songs when accessing /audio/funny', function () {
    $file = File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    Reaction::create([
        'user_id' => $this->user->id,
        'file_id' => $file->id,
        'type' => 'funny',
    ]);

    $response = $this->actingAs($this->user)->get('/audio/funny');

    $response->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('audio/Index')
            ->where('playlistId', null)
        );
});

it('returns disliked songs when accessing /audio/disliked', function () {
    $file = File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    Reaction::create([
        'user_id' => $this->user->id,
        'file_id' => $file->id,
        'type' => 'disliked',
    ]);

    $response = $this->actingAs($this->user)->get('/audio/disliked');

    $response->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('audio/Index')
            ->where('playlistId', null)
        );
});

it('returns missing songs when accessing /audio/missing', function () {
    $file = File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'not_found' => true,
        'blacklisted_at' => null,
    ]);

    $response = $this->actingAs($this->user)->get('/audio/missing');

    $response->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('audio/Index')
            ->where('playlistId', null)
        );
});

it('returns unrated songs when accessing /audio/unrated', function () {
    $file = File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    $response = $this->actingAs($this->user)->get('/audio/unrated');

    $response->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('audio/Index')
            ->where('playlistId', null)
        );
});

it('returns spotify songs when accessing /audio/spotify', function () {
    $file = File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => null,
        'source' => 'spotify',
    ]);

    $response = $this->actingAs($this->user)->get('/audio/spotify');

    $response->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('audio/Index')
            ->where('playlistId', null)
        );
});

it('returns 404 for invalid reaction type', function () {
    $response = $this->actingAs($this->user)->get('/audio/invalid');

    $response->assertNotFound();
});

it('requires authentication for all reaction types', function () {
    $types = ['all', 'favorites', 'liked', 'funny', 'disliked', 'missing', 'unrated', 'spotify'];

    foreach ($types as $type) {
        $response = $this->get("/audio/{$type}");
        $response->assertRedirect('/login');
    }
});

it('returns JSON data when accessing data endpoint', function () {
    $file = File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'not_found' => false,
        'blacklisted_at' => null,
    ]);

    Reaction::create([
        'user_id' => $this->user->id,
        'file_id' => $file->id,
        'type' => 'loved',
    ]);

    $response = $this->actingAs($this->user)->getJson('/audio/favorites/data');

    $response->assertSuccessful()
        ->assertJson([
            'playlistId' => null,
            'isSpotifyPlaylist' => false,
        ]);
});
