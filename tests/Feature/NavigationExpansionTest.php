<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('audio navigation remains expanded when accessing sub-items', function () {
    // Test accessing audio favorites page
    $response = $this->actingAs($this->user)->get('/audio/favorites');

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Audio')
        ->has('files')
    );
});

test('audio navigation sub-routes are accessible', function () {
    $subRoutes = [
        '/audio/favorites',
        '/audio/liked',
        '/audio/disliked',
        '/audio/unrated',
        '/audio/artists',
        '/audio/albums',
        '/audio/playlists'
    ];

    foreach ($subRoutes as $route) {
        $response = $this->actingAs($this->user)->get($route);
        $response->assertStatus(200);
    }
});
