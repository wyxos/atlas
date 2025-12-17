<?php

use App\Models\Container;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('authenticated user can view blacklisted containers', function () {
    $user = User::factory()->create();
    $container1 = Container::factory()->create([
        'blacklisted_at' => now()->subDays(2),
        'action_type' => 'ui_countdown',
    ]);
    $container2 = Container::factory()->create([
        'blacklisted_at' => now()->subDays(1),
        'action_type' => 'auto_dislike',
    ]);
    Container::factory()->create(['blacklisted_at' => null]); // Not blacklisted

    $response = $this->actingAs($user)->getJson('/api/container-blacklists');

    $response->assertSuccessful();
    $data = $response->json();
    expect($data)->toBeArray();
    expect(count($data))->toBe(2);
    // Should be ordered by blacklisted_at desc (newest first)
    expect($data[0]['id'])->toBe($container2->id);
    expect($data[1]['id'])->toBe($container1->id);
});

test('blacklisted containers are ordered by blacklisted_at desc', function () {
    $user = User::factory()->create();
    $container1 = Container::factory()->create([
        'blacklisted_at' => now()->subDays(3),
    ]);
    $container2 = Container::factory()->create([
        'blacklisted_at' => now()->subDays(1),
    ]);
    $container3 = Container::factory()->create([
        'blacklisted_at' => now()->subDays(2),
    ]);

    $response = $this->actingAs($user)->getJson('/api/container-blacklists');

    $response->assertSuccessful();
    $data = $response->json();
    expect($data[0]['id'])->toBe($container2->id);
    expect($data[1]['id'])->toBe($container3->id);
    expect($data[2]['id'])->toBe($container1->id);
});

test('only blacklisted containers are returned', function () {
    $user = User::factory()->create();
    Container::factory()->create(['blacklisted_at' => now()]);
    Container::factory()->create(['blacklisted_at' => null]);
    Container::factory()->create(['blacklisted_at' => null]);

    $response = $this->actingAs($user)->getJson('/api/container-blacklists');

    $response->assertSuccessful();
    $data = $response->json();
    expect(count($data))->toBe(1);
});

test('returns empty array when no blacklisted containers exist', function () {
    $user = User::factory()->create();
    Container::factory()->count(3)->create(['blacklisted_at' => null]);

    $response = $this->actingAs($user)->getJson('/api/container-blacklists');

    $response->assertSuccessful();
    $data = $response->json();
    expect($data)->toBeArray();
    expect($data)->toBeEmpty();
});

test('guest cannot view blacklisted containers', function () {
    $response = $this->getJson('/api/container-blacklists');

    $response->assertUnauthorized();
});
