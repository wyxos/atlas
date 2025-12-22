<?php

use App\Models\Container;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('authenticated user can remove container from blacklist', function () {
    $user = User::factory()->create();
    $container = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'dislike',
    ]);

    $response = $this->actingAs($user)->deleteJson("/api/container-blacklists/{$container->id}");

    $response->assertSuccessful();
    $response->assertJson([
        'message' => 'Container removed from blacklist successfully',
    ]);

    $container->refresh();
    expect($container->blacklisted_at)->toBeNull();
    expect($container->action_type)->toBeNull();
});

test('removing blacklist clears both blacklisted_at and action_type', function () {
    $user = User::factory()->create();
    $container = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'blacklist',
    ]);

    $this->actingAs($user)->deleteJson("/api/container-blacklists/{$container->id}");

    $container->refresh();
    expect($container->blacklisted_at)->toBeNull();
    expect($container->action_type)->toBeNull();
});

test('returns 404 when container is not blacklisted', function () {
    $user = User::factory()->create();
    $container = Container::factory()->create([
        'blacklisted_at' => null,
        'action_type' => null,
    ]);

    $response = $this->actingAs($user)->deleteJson("/api/container-blacklists/{$container->id}");

    $response->assertNotFound();
    $response->assertJson([
        'message' => 'Container is not blacklisted',
    ]);
});

test('guest cannot remove container from blacklist', function () {
    $container = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'dislike',
    ]);

    $response = $this->deleteJson("/api/container-blacklists/{$container->id}");

    $response->assertUnauthorized();
});
