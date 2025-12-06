<?php

use App\Models\BrowseTab;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('user can update their own tab position', function () {
    $user = User::factory()->create();
    $tab = BrowseTab::factory()->for($user)->create(['position' => 0]);

    $response = $this->actingAs($user)->patchJson("/api/browse-tabs/{$tab->id}/position", [
        'position' => 5,
    ]);

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['position'])->toBe(5);
    $this->assertDatabaseHas('browse_tabs', [
        'id' => $tab->id,
        'position' => 5,
    ]);
});

test('user cannot update another user tab position', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $tab = BrowseTab::factory()->for($user2)->create(['position' => 0]);

    $response = $this->actingAs($user1)->patchJson("/api/browse-tabs/{$tab->id}/position", [
        'position' => 5,
    ]);

    $response->assertForbidden();
    $this->assertDatabaseHas('browse_tabs', [
        'id' => $tab->id,
        'position' => 0,
    ]);
});

test('position update requires position parameter', function () {
    $user = User::factory()->create();
    $tab = BrowseTab::factory()->for($user)->create();

    $response = $this->actingAs($user)->patchJson("/api/browse-tabs/{$tab->id}/position", []);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('position');
});

test('position update validates position is integer', function () {
    $user = User::factory()->create();
    $tab = BrowseTab::factory()->for($user)->create();

    $response = $this->actingAs($user)->patchJson("/api/browse-tabs/{$tab->id}/position", [
        'position' => 'not-an-integer',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('position');
});

test('position update validates position is non-negative', function () {
    $user = User::factory()->create();
    $tab = BrowseTab::factory()->for($user)->create();

    $response = $this->actingAs($user)->patchJson("/api/browse-tabs/{$tab->id}/position", [
        'position' => -1,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('position');
});

test('position update returns updated tab', function () {
    $user = User::factory()->create();
    $tab = BrowseTab::factory()->for($user)->create();

    $response = $this->actingAs($user)->patchJson("/api/browse-tabs/{$tab->id}/position", [
        'position' => 10,
    ]);

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['id'])->toBe($tab->id);
    expect($data['position'])->toBe(10);
});

test('guest cannot update tab positions', function () {
    $user = User::factory()->create();
    $tab = BrowseTab::factory()->for($user)->create();

    $response = $this->patchJson("/api/browse-tabs/{$tab->id}/position", [
        'position' => 5,
    ]);

    $response->assertUnauthorized();
});

test('updating non-existent tab position returns 404', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->patchJson('/api/browse-tabs/99999/position', [
        'position' => 5,
    ]);

    $response->assertNotFound();
});

