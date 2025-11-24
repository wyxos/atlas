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
    // Wait for Vue to fully render
    $page->waitFor('button:has-text("Demo User")')
        ->click('button:has-text("Demo User")')
        ->waitFor('text=Logout')
        ->click('text=Logout')
        ->waitForUrl('/')
        ->assertPathIs('/')
        ->assertSee('Atlas');

    // Verify user is actually logged out
    $this->assertGuest();

    // Verify user cannot access dashboard after logout
    $page = visit('/dashboard');
    $page->assertPathBeginsWith('/login');
});
