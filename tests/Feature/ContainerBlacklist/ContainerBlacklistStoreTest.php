<?php

use App\Models\Container;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('authenticated user can create container blacklist', function () {
    $user = User::factory()->create();
    $container = Container::factory()->create([
        'type' => 'User',
        'source' => 'CivitAI',
        'blacklisted_at' => null,
    ]);

    $response = $this->actingAs($user)->postJson('/api/container-blacklists', [
        'container_id' => $container->id,
        'action_type' => 'ui_countdown',
    ]);

    $response->assertStatus(201);
    $data = $response->json();
    expect($data['id'])->toBe($container->id);
    expect($data['action_type'])->toBe('ui_countdown');
    expect($data['blacklisted_at'])->not->toBeNull();

    $this->assertDatabaseHas('containers', [
        'id' => $container->id,
        'action_type' => 'ui_countdown',
        'blacklisted_at' => now(),
    ]);
});

test('can create blacklist with auto_dislike action type', function () {
    $user = User::factory()->create();
    $container = Container::factory()->create([
        'type' => 'User',
        'source' => 'CivitAI',
    ]);

    $response = $this->actingAs($user)->postJson('/api/container-blacklists', [
        'container_id' => $container->id,
        'action_type' => 'auto_dislike',
    ]);

    $response->assertStatus(201);
    $data = $response->json();
    expect($data['action_type'])->toBe('auto_dislike');
});

test('can create blacklist with blacklist action type', function () {
    $user = User::factory()->create();
    $container = Container::factory()->create([
        'type' => 'User',
        'source' => 'CivitAI',
    ]);

    $response = $this->actingAs($user)->postJson('/api/container-blacklists', [
        'container_id' => $container->id,
        'action_type' => 'blacklist',
    ]);

    $response->assertStatus(201);
    $data = $response->json();
    expect($data['action_type'])->toBe('blacklist');
});

test('validates container_id is required', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/container-blacklists', [
        'action_type' => 'ui_countdown',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('container_id');
});

test('validates container_id exists', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/container-blacklists', [
        'container_id' => 99999,
        'action_type' => 'ui_countdown',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('container_id');
});

test('validates action_type is required', function () {
    $user = User::factory()->create();
    $container = Container::factory()->create(['type' => 'User', 'source' => 'CivitAI']);

    $response = $this->actingAs($user)->postJson('/api/container-blacklists', [
        'container_id' => $container->id,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('action_type');
});

test('validates action_type is valid', function () {
    $user = User::factory()->create();
    $container = Container::factory()->create(['type' => 'User', 'source' => 'CivitAI']);

    $response = $this->actingAs($user)->postJson('/api/container-blacklists', [
        'container_id' => $container->id,
        'action_type' => 'invalid_type',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('action_type');
});

test('rejects non-blacklistable container types', function () {
    $user = User::factory()->create();
    // Post type is not blacklistable (only User is for CivitAI)
    $container = Container::factory()->create([
        'type' => 'Post',
        'source' => 'CivitAI',
    ]);

    $response = $this->actingAs($user)->postJson('/api/container-blacklists', [
        'container_id' => $container->id,
        'action_type' => 'ui_countdown',
    ]);

    $response->assertStatus(422);
    $response->assertJson([
        'message' => "Container type 'Post' is not blacklistable.",
    ]);
});

test('updates existing container blacklist', function () {
    $user = User::factory()->create();
    $container = Container::factory()->create([
        'type' => 'User',
        'source' => 'CivitAI',
        'action_type' => 'ui_countdown',
        'blacklisted_at' => now()->subDays(1),
    ]);

    $response = $this->actingAs($user)->postJson('/api/container-blacklists', [
        'container_id' => $container->id,
        'action_type' => 'auto_dislike',
    ]);

    $response->assertStatus(201);
    $container->refresh();
    expect($container->action_type)->toBe('auto_dislike');
    expect($container->blacklisted_at)->not->toBeNull();
});

test('guest cannot create container blacklist', function () {
    $container = Container::factory()->create(['type' => 'User', 'source' => 'CivitAI']);

    $response = $this->postJson('/api/container-blacklists', [
        'container_id' => $container->id,
        'action_type' => 'ui_countdown',
    ]);

    $response->assertUnauthorized();
});
