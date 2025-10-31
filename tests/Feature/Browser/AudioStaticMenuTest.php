<?php

use App\Models\User;

it('can access all songs page directly', function () {
    $user = User::factory()->create();

    $page = visit('/login');
    $page->fill('email', $user->email)
        ->fill('password', 'password')
        ->press('Log in');

    // Navigate directly to the audio all songs page
    $page = visit('/audio/all');

    // Should load successfully
    $page->assertPathIs('/audio/all');
    $page->assertNoSmoke();
    $page->assertSee('Audio Library');
});

it('can access favorites page directly', function () {
    $user = User::factory()->create();

    $page = visit('/login');
    $page->fill('email', $user->email)
        ->fill('password', 'password')
        ->press('Log in');

    // Navigate directly to the audio favorites page
    $page = visit('/audio/favorites');

    // Should load successfully
    $page->assertPathIs('/audio/favorites');
    $page->assertNoSmoke();
    $page->assertSee('Audio Library');
});

it('can access liked page directly', function () {
    $user = User::factory()->create();

    $page = visit('/login');
    $page->fill('email', $user->email)
        ->fill('password', 'password')
        ->press('Log in');

    // Navigate directly to the audio liked page
    $page = visit('/audio/liked');

    // Should load successfully
    $page->assertPathIs('/audio/liked');
    $page->assertNoSmoke();
    $page->assertSee('Audio Library');
});

it('can access funny page directly', function () {
    $user = User::factory()->create();

    $page = visit('/login');
    $page->fill('email', $user->email)
        ->fill('password', 'password')
        ->press('Log in');

    // Navigate directly to the audio funny page
    $page = visit('/audio/funny');

    // Should load successfully
    $page->assertPathIs('/audio/funny');
    $page->assertNoSmoke();
    $page->assertSee('Audio Library');
});

it('can access disliked page directly', function () {
    $user = User::factory()->create();

    $page = visit('/login');
    $page->fill('email', $user->email)
        ->fill('password', 'password')
        ->press('Log in');

    // Navigate directly to the audio disliked page
    $page = visit('/audio/disliked');

    // Should load successfully
    $page->assertPathIs('/audio/disliked');
    $page->assertNoSmoke();
    $page->assertSee('Audio Library');
});

it('can access missing page directly', function () {
    $user = User::factory()->create();

    $page = visit('/login');
    $page->fill('email', $user->email)
        ->fill('password', 'password')
        ->press('Log in');

    // Navigate directly to the audio missing page
    $page = visit('/audio/missing');

    // Should load successfully
    $page->assertPathIs('/audio/missing');
    $page->assertNoSmoke();
    $page->assertSee('Audio Library');
});

it('shows play and shuffle buttons for all static menu items', function () {
    $user = User::factory()->create();

    $page = visit('/login');
    $page->fill('email', $user->email)
        ->fill('password', 'password')
        ->press('Log in');

    // Navigate to dashboard
    $page = visit(route('dashboard'));
    $page->assertSee('Dashboard');

    // First expand the Playlists section if needed
    try {
        $page->click('Playlists');
    } catch (\Exception $e) {
        // Ignore if already expanded
    }

    // Check all songs buttons
    $page->assertAttribute('@sidebar-all-play', 'title', 'Play')
        ->assertAttribute('@sidebar-all-shuffle', 'title', 'Shuffle');

    // Check favorites buttons
    $page->assertAttribute('@sidebar-favorites-play', 'title', 'Play')
        ->assertAttribute('@sidebar-favorites-shuffle', 'title', 'Shuffle');

    // Check liked buttons
    $page->assertAttribute('@sidebar-liked-play', 'title', 'Play')
        ->assertAttribute('@sidebar-liked-shuffle', 'title', 'Shuffle');

    // Check funny buttons
    $page->assertAttribute('@sidebar-funny-play', 'title', 'Play')
        ->assertAttribute('@sidebar-funny-shuffle', 'title', 'Shuffle');

    // Check disliked buttons
    $page->assertAttribute('@sidebar-disliked-play', 'title', 'Play')
        ->assertAttribute('@sidebar-disliked-shuffle', 'title', 'Shuffle');

    // Check missing buttons
    $page->assertAttribute('@sidebar-missing-play', 'title', 'Play')
        ->assertAttribute('@sidebar-missing-shuffle', 'title', 'Shuffle');
});
