<?php

use App\Models\Tab;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('authenticated user can create browse tab', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/tabs', [
        'label' => 'My Tab',
    ]);

    $response->assertStatus(201);
    $response->assertJsonStructure([
        'id',
        'label',
        'user_id',
        'position',
    ]);
    $this->assertDatabaseHas('tabs', [
        'label' => 'My Tab',
        'user_id' => $user->id,
    ]);
});

test('tab creation requires label', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/tabs', []);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('label');
});

test('tab creation accepts optional params', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/tabs', [
        'label' => 'My Tab',
        'params' => ['page' => 2, 'filter' => 'test'],
    ]);

    $response->assertStatus(201);
    $data = $response->json();
    expect($data['params'])->toBe(['page' => 2, 'filter' => 'test']);
});

test('tab creation accepts optional position', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/tabs', [
        'label' => 'My Tab',
        'position' => 5,
    ]);

    $response->assertStatus(201);
    $data = $response->json();
    expect($data['position'])->toBe(5);
});

test('tab creation auto-assigns position when not provided max plus one', function () {
    $user = User::factory()->create();
    Tab::factory()->for($user)->create(['position' => 2]);
    Tab::factory()->for($user)->create(['position' => 5]);

    $response = $this->actingAs($user)->postJson('/api/tabs', [
        'label' => 'My Tab',
    ]);

    $response->assertStatus(201);
    $data = $response->json();
    expect($data['position'])->toBe(6); // max(2, 5) + 1
});

test('tab creation assigns position 0 when no existing tabs', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/tabs', [
        'label' => 'My Tab',
    ]);

    $response->assertStatus(201);
    $data = $response->json();
    expect($data['position'])->toBe(0);
});

test('tab creation returns 201 status', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/tabs', [
        'label' => 'My Tab',
    ]);

    $response->assertStatus(201);
});

test('tab creation returns created tab in response', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/tabs', [
        'label' => 'My Tab',
    ]);

    $response->assertStatus(201);
    $data = $response->json();
    expect($data['label'])->toBe('My Tab');
    expect($data['user_id'])->toBe($user->id);
});

test('tab is associated with authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/tabs', [
        'label' => 'My Tab',
    ]);

    $this->assertDatabaseHas('tabs', [
        'label' => 'My Tab',
        'user_id' => $user->id,
    ]);
});

test('guest cannot create browse tabs', function () {
    $response = $this->postJson('/api/tabs', [
        'label' => 'My Tab',
    ]);

    $response->assertUnauthorized();
});

test('validation fails when label is missing', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/tabs', []);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('label');
});

test('validation fails when label exceeds max length', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/tabs', [
        'label' => str_repeat('a', 256),
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('label');
});

test('validation fails when position is negative', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/tabs', [
        'label' => 'My Tab',
        'position' => -1,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('position');
});

test('validation fails when params is not array', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/tabs', [
        'label' => 'My Tab',
        'params' => 'not-an-array',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('params');
});

test('tab creation does not set default feed value', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/tabs', [
        'label' => 'My Tab',
    ]);

    $response->assertStatus(201);
    $data = $response->json();
    // Feed is not set by default - it's only set when explicitly provided
    expect($data['params']['feed'] ?? null)->toBeNull();
    $this->assertDatabaseHas('tabs', [
        'id' => $data['id'],
    ]);
    $tab = \App\Models\Tab::find($data['id']);
    expect($tab->params['feed'] ?? null)->toBeNull();
});

test('tab creation accepts offline source type', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/tabs', [
        'label' => 'My Tab',
        'params' => ['feed' => 'local'],
    ]);

    $response->assertStatus(201);
    $data = $response->json();
    expect($data['params']['feed'] ?? null)->toBe('local');
    $this->assertDatabaseHas('tabs', [
        'id' => $data['id'],
    ]);
    $tab = \App\Models\Tab::find($data['id']);
    expect($tab->params['feed'] ?? null)->toBe('local');
});

test('tab creation accepts online source type', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/tabs', [
        'label' => 'My Tab',
        'params' => ['feed' => 'online'],
    ]);

    $response->assertStatus(201);
    $data = $response->json();
    expect($data['params']['feed'] ?? null)->toBe('online');
});

test('validation fails when feed is invalid', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/tabs', [
        'label' => 'My Tab',
        'params' => ['feed' => 'invalid'],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('params.feed');
});
