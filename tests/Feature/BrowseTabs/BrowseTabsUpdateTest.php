<?php

use App\Models\BrowseTab;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('user can update their own browse tab', function () {
    $user = User::factory()->create();
    $tab = BrowseTab::factory()->for($user)->create(['label' => 'Old Label']);

    $response = $this->actingAs($user)->putJson("/api/browse-tabs/{$tab->id}", [
        'label' => 'New Label',
    ]);

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['label'])->toBe('New Label');
    $this->assertDatabaseHas('browse_tabs', [
        'id' => $tab->id,
        'label' => 'New Label',
    ]);
});

test('user cannot update another user tab', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $tab = BrowseTab::factory()->for($user2)->create(['label' => 'Original Label']);

    $response = $this->actingAs($user1)->putJson("/api/browse-tabs/{$tab->id}", [
        'label' => 'Hacked Label',
    ]);

    $response->assertForbidden();
    $this->assertDatabaseHas('browse_tabs', [
        'id' => $tab->id,
        'label' => 'Original Label',
    ]);
});

test('tab update accepts partial data sometimes rules', function () {
    $user = User::factory()->create();
    $tab = BrowseTab::factory()->for($user)->create([
        'label' => 'Original Label',
        'query_params' => ['page' => 1],
    ]);

    $response = $this->actingAs($user)->putJson("/api/browse-tabs/{$tab->id}", [
        'label' => 'Updated Label',
    ]);

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['label'])->toBe('Updated Label');
    // query_params should remain unchanged
    expect($data['query_params'])->toBe(['page' => 1]);
});

test('tab update validates label when provided', function () {
    $user = User::factory()->create();
    $tab = BrowseTab::factory()->for($user)->create();

    $response = $this->actingAs($user)->putJson("/api/browse-tabs/{$tab->id}", [
        'label' => str_repeat('a', 256),
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('label');
});

test('tab update validates position when provided', function () {
    $user = User::factory()->create();
    $tab = BrowseTab::factory()->for($user)->create();

    $response = $this->actingAs($user)->putJson("/api/browse-tabs/{$tab->id}", [
        'position' => -1,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('position');
});

test('tab update returns updated tab', function () {
    $user = User::factory()->create();
    $tab = BrowseTab::factory()->for($user)->create();

    $response = $this->actingAs($user)->putJson("/api/browse-tabs/{$tab->id}", [
        'label' => 'Updated Label',
        'query_params' => ['page' => 2],
    ]);

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['id'])->toBe($tab->id);
    expect($data['label'])->toBe('Updated Label');
    expect($data['query_params'])->toBe(['page' => 2]);
});

test('guest cannot update browse tabs', function () {
    $user = User::factory()->create();
    $tab = BrowseTab::factory()->for($user)->create();

    $response = $this->putJson("/api/browse-tabs/{$tab->id}", [
        'label' => 'Updated Label',
    ]);

    $response->assertUnauthorized();
});

test('updating non-existent tab returns 404', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->putJson('/api/browse-tabs/99999', [
        'label' => 'Updated Label',
    ]);

    $response->assertNotFound();
});

