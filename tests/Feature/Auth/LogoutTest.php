<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('authenticated user can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $response->assertRedirect('/');
    $this->assertGuest();
});

test('logout invalidates session', function () {
    $user = User::factory()->create();

    $sessionId = session()->getId();

    $this->actingAs($user)->post('/logout');

    // Session should be invalidated
    $this->assertGuest();
});

test('logout regenerates CSRF token', function () {
    $user = User::factory()->create();

    $oldToken = csrf_token();

    $this->actingAs($user)->post('/logout');

    $newToken = csrf_token();
    expect($newToken)->not->toBe($oldToken);
});

test('logout redirects to home page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $response->assertRedirect('/');
});

test('guest cannot access logout route', function () {
    $response = $this->post('/logout');

    $response->assertRedirect('/login');
});

test('logout clears authentication', function () {
    $user = User::factory()->create();

    $this->actingAs($user);
    $this->assertAuthenticatedAs($user);

    $this->post('/logout');

    $this->assertGuest();
});
