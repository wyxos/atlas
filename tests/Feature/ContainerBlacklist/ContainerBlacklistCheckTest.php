<?php

use App\Models\Container;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('authenticated user can check if container is blacklisted', function () {
    $user = User::factory()->create();
    $container = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'dislike',
    ]);

    $response = $this->actingAs($user)->getJson("/api/container-blacklists/{$container->id}/check");

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['blacklisted'])->toBeTrue();
    expect($data['blacklisted_at'])->not->toBeNull();
    expect($data['action_type'])->toBe('dislike');
});

test('check returns false for non-blacklisted container', function () {
    $user = User::factory()->create();
    $container = Container::factory()->create([
        'blacklisted_at' => null,
        'action_type' => null,
    ]);

    $response = $this->actingAs($user)->getJson("/api/container-blacklists/{$container->id}/check");

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['blacklisted'])->toBeFalse();
    expect($data['blacklisted_at'])->toBeNull();
    expect($data['action_type'])->toBeNull();
});

test('check returns correct action type', function () {
    $user = User::factory()->create();
    $container = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'blacklist',
    ]);

    $response = $this->actingAs($user)->getJson("/api/container-blacklists/{$container->id}/check");

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['action_type'])->toBe('blacklist');
});

test('guest cannot check container blacklist status', function () {
    $container = Container::factory()->create();

    $response = $this->getJson("/api/container-blacklists/{$container->id}/check");

    $response->assertUnauthorized();
});
