<?php

use App\Models\User;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('allows a guest to log in and sign out without any issues', function () {
    $user = User::factory()->create([
        'email' => 'demo@atlas.test',
        'password' => bcrypt('password'),
    ]);

    // Visit login page as guest
    $page = visit('/login');
    $page->assertSee('Welcome Back');

    // Fill in login form and submit
    $page->fill('email', 'demo@atlas.test')
        ->fill('password', 'password')
        ->press('Sign In')
        ->assertPathBeginsWith('/dashboard')
        ->assertSee('Dashboard');

    $this->assertAuthenticatedAs($user);

    // Click the sign out button in the header menu
    $page->click('button[aria-label="User menu"]')
        ->assertSee('Sign Out')
        ->click('Sign Out')
        ->assertPathBeginsWith('/')
        ->assertSee('Atlas');

    $this->assertGuest();
});
