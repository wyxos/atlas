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

    // Click the user menu in the sidebar to expand it, then click logout
    $page->click('button:has-text("Demo User")')
        ->waitForText('Logout')
        ->click('Logout')
        ->assertPathIs('/')
        ->assertSee('Atlas');

    // Verify user is actually logged out
    $this->assertGuest();

    // Verify user cannot access dashboard after logout
    $page = visit('/dashboard');
    $page->assertPathBeginsWith('/login');
});
