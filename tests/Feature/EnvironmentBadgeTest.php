<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('environment prop is shared in inertia responses', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->has('environment')
            ->where('environment', app()->environment())
        );
});

test('environment prop is shared for unauthenticated users', function () {
    $response = $this->get('/');

    $response->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->has('environment')
            ->where('environment', app()->environment())
        );
});
