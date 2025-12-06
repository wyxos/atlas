<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guest can view login form', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
    $response->assertViewIs('auth.login');
});

test('authenticated user redirected from login form', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/login');

    // In Laravel, when no intended URL is set, it redirects to home
    $response->assertRedirect('/');
});

test('login form returns correct view', function () {
    $response = $this->get('/login');

    $response->assertViewIs('auth.login');
});

