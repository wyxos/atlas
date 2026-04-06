<?php

use App\Models\Container;
use App\Models\File;
use App\Models\Reaction;
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
    expect($data['file_stats'])->toBe([
        'unreacted' => 0,
        'blacklisted' => 0,
        'disliked' => 0,
        'positive' => 0,
    ]);
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
    expect($data['file_stats'])->toBe([
        'unreacted' => 0,
        'blacklisted' => 0,
        'disliked' => 0,
        'positive' => 0,
    ]);
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

test('check returns container file stats scoped to the current user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $container = Container::factory()->create();

    $positiveFile = File::factory()->create();
    $blacklistedFile = File::factory()->create([
        'blacklisted_at' => now(),
    ]);
    $notFoundFile = File::factory()->create([
        'not_found' => true,
    ]);
    $otherUserPositiveFile = File::factory()->create();
    $untouchedFile = File::factory()->create();
    $dislikedFile = File::factory()->create();

    $container->files()->attach([
        $positiveFile->id,
        $blacklistedFile->id,
        $notFoundFile->id,
        $otherUserPositiveFile->id,
        $untouchedFile->id,
        $dislikedFile->id,
    ]);

    Reaction::query()->create([
        'file_id' => $positiveFile->id,
        'user_id' => $user->id,
        'type' => 'like',
    ]);

    Reaction::query()->create([
        'file_id' => $otherUserPositiveFile->id,
        'user_id' => $otherUser->id,
        'type' => 'love',
    ]);

    Reaction::query()->create([
        'file_id' => $dislikedFile->id,
        'user_id' => $user->id,
        'type' => 'dislike',
    ]);

    $response = $this->actingAs($user)->getJson("/api/container-blacklists/{$container->id}/check");

    $response->assertSuccessful();
    expect($response->json('file_stats'))->toBe([
        'unreacted' => 2,
        'blacklisted' => 1,
        'disliked' => 1,
        'positive' => 1,
    ]);
});

test('guest cannot check container blacklist status', function () {
    $container = Container::factory()->create();

    $response = $this->getJson("/api/container-blacklists/{$container->id}/check");

    $response->assertUnauthorized();
});
