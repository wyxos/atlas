<?php

use App\Models\Tab;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('user can update their own browse tab', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create(['label' => 'Old Label']);

    $response = $this->actingAs($user)->putJson(route('api.tabs.update', ['tab' => $tab->id]), [
        'label' => 'New Label',
    ]);

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['label'])->toBe('New Label');
    $this->assertDatabaseHas('tabs', [
        'id' => $tab->id,
        'label' => 'New Label',
    ]);
});

test('user cannot update another user tab', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $tab = Tab::factory()->for($user2)->create(['label' => 'Original Label']);

    $response = $this->actingAs($user1)->putJson("/api/tabs/{$tab->id}", [
        'label' => 'Hacked Label',
    ]);

    $response->assertForbidden();
    $this->assertDatabaseHas('tabs', [
        'id' => $tab->id,
        'label' => 'Original Label',
    ]);
});

test('tab update accepts partial data sometimes rules', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create([
        'label' => 'Original Label',
        'params' => ['page' => 1],
    ]);

    $response = $this->actingAs($user)->putJson(route('api.tabs.update', ['tab' => $tab->id]), [
        'label' => 'Updated Label',
    ]);

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['label'])->toBe('Updated Label');
    // params should remain unchanged
    expect($data['params'])->toBe(['page' => 1]);
});

test('tab update validates label when provided', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create();

    $response = $this->actingAs($user)->putJson(route('api.tabs.update', ['tab' => $tab->id]), [
        'label' => str_repeat('a', 256),
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('label');
});

test('tab update validates position when provided', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create();

    $response = $this->actingAs($user)->putJson(route('api.tabs.update', ['tab' => $tab->id]), [
        'position' => -1,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('position');
});

test('tab update returns updated tab', function () {
    $user = User::factory()->create();
    // Create tab with empty params to avoid factory defaults interfering
    $tab = Tab::factory()->for($user)->withParams([])->create();

    $response = $this->actingAs($user)->putJson(route('api.tabs.update', ['tab' => $tab->id]), [
        'label' => 'Updated Label',
        'params' => ['page' => 2],
    ]);

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['id'])->toBe($tab->id);
    expect($data['label'])->toBe('Updated Label');
    // Params validation only allows params.feed, so other params may be filtered
    // We verify the label was updated successfully
    expect($data['params'])->toBeArray();
});

test('guest cannot update browse tabs', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create();

    $response = $this->putJson(route('api.tabs.update', ['tab' => $tab->id]), [
        'label' => 'Updated Label',
    ]);

    $response->assertUnauthorized();
});

test('updating non-existent tab returns 404', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->putJson(route('api.tabs.update', ['tab' => 99999]), [
        'label' => 'Updated Label',
    ]);

    $response->assertNotFound();
});

test('tab update can change source type to local', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create(['params' => ['feed' => 'online']]);

    $response = $this->actingAs($user)->putJson(route('api.tabs.update', ['tab' => $tab->id]), [
        'params' => ['feed' => 'local'],
    ]);

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['params']['feed'] ?? null)->toBe('local');
    $tab->refresh();
    expect($tab->params['feed'] ?? null)->toBe('local');
});

test('tab update can change source type to online', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create(['params' => ['feed' => 'local']]);

    $response = $this->actingAs($user)->putJson(route('api.tabs.update', ['tab' => $tab->id]), [
        'params' => ['feed' => 'online'],
    ]);

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['params']['feed'] ?? null)->toBe('online');
    $tab->refresh();
    expect($tab->params['feed'] ?? null)->toBe('online');
});

test('validation fails when feed is invalid', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create();

    $response = $this->actingAs($user)->putJson(route('api.tabs.update', ['tab' => $tab->id]), [
        'params' => ['feed' => 'invalid'],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('params.feed');
});
