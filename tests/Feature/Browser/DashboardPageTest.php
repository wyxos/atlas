<?php

use App\Models\User;

// Admin can access dashboard and sees Users link
// Also follows the WelcomePageTest style: visit -> assert -> click -> assert

test('admin can access dashboard and see users link', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    $response = visit(route('dashboard'));

    $response->assertNoSmoke();

    $response->assertSee('Dashboard');
    $response->assertSee('Users');
});

// Non-admin can access dashboard but should not see the Users link in the sidebar

test('non-admin does not see users link on dashboard', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $this->actingAs($user);

    $response = visit(route('dashboard'));

    $response->assertNoSmoke();

    $response->assertSee('Dashboard');
    $response->assertDontSee('Users');
});

// Admin can navigate from dashboard to the users list via the Users link

test('admin can navigate to users list from dashboard', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    $response = visit(route('dashboard'));

    $response->assertNoSmoke();

    $response->click('Users')
        ->assertUrlIs(route('users'))
        ->assertNoSmoke()
        ->assertSee('Users')
        ->assertSee('Email')
        ->assertSee('Name')
        ->assertSee('ID');
});
