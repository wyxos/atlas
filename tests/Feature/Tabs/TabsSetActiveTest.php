<?php

use App\Models\Tab;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('user can set their own tab as active', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create(['is_active' => false]);

    $response = $this->actingAs($user)->patchJson("/api/tabs/{$tab->id}/active");

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['is_active'])->toBeTrue();

    $tab->refresh();
    expect($tab->is_active)->toBeTrue();
});

test('setting tab as active deactivates other tabs', function () {
    $user = User::factory()->create();
    $tab1 = Tab::factory()->for($user)->create(['is_active' => true]);
    $tab2 = Tab::factory()->for($user)->create(['is_active' => false]);
    $tab3 = Tab::factory()->for($user)->create(['is_active' => true]);

    $response = $this->actingAs($user)->patchJson("/api/tabs/{$tab2->id}/active");

    $response->assertSuccessful();

    $tab1->refresh();
    $tab2->refresh();
    $tab3->refresh();

    expect($tab1->is_active)->toBeFalse();
    expect($tab2->is_active)->toBeTrue();
    expect($tab3->is_active)->toBeFalse();
});

test('user cannot set another user tab as active', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $tab = Tab::factory()->for($user2)->create(['is_active' => false]);

    $response = $this->actingAs($user1)->patchJson("/api/tabs/{$tab->id}/active");

    $response->assertForbidden();
    expect($tab->fresh()->is_active)->toBeFalse();
});

test('guest cannot set tab as active', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create();

    $response = $this->patchJson("/api/tabs/{$tab->id}/active");

    $response->assertUnauthorized();
});

test('setting non-existent tab returns 404', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->patchJson('/api/tabs/99999/active');

    $response->assertNotFound();
});

test('returns updated tab in response', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create(['is_active' => false]);

    $response = $this->actingAs($user)->patchJson("/api/tabs/{$tab->id}/active");

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['id'])->toBe($tab->id);
    expect($data['is_active'])->toBeTrue();
});
