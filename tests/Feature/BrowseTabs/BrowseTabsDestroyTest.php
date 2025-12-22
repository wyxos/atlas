<?php

use App\Models\Tab;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('user can delete their own browse tab', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create();

    $response = $this->actingAs($user)->deleteJson("/api/tabs/{$tab->id}");

    $response->assertSuccessful();
    $response->assertJson([
        'message' => 'Tab deleted successfully',
    ]);
    $this->assertDatabaseMissing('tabs', ['id' => $tab->id]);
});

test('user cannot delete another user tab', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $tab = Tab::factory()->for($user2)->create();

    $response = $this->actingAs($user1)->deleteJson("/api/tabs/{$tab->id}");

    $response->assertForbidden();
    $this->assertDatabaseHas('tabs', ['id' => $tab->id]);
});

test('tab deletion returns success message', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create();

    $response = $this->actingAs($user)->deleteJson("/api/tabs/{$tab->id}");

    $response->assertJson([
        'message' => 'Tab deleted successfully',
    ]);
});

test('deleted tab is removed from database', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create();
    $tabId = $tab->id;

    $this->actingAs($user)->deleteJson("/api/tabs/{$tabId}");

    $this->assertDatabaseMissing('tabs', ['id' => $tabId]);
});

test('guest cannot delete browse tabs', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create();

    $response = $this->deleteJson("/api/tabs/{$tab->id}");

    $response->assertUnauthorized();
    $this->assertDatabaseHas('tabs', ['id' => $tab->id]);
});

test('deleting non-existent tab returns 404', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->deleteJson('/api/tabs/99999');

    $response->assertNotFound();
});
