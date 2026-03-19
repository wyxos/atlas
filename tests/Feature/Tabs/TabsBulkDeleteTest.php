<?php

use App\Models\Tab;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('user can bulk delete tabs and set the next active tab', function () {
    $user = User::factory()->create();
    $tabOne = Tab::factory()->for($user)->create(['position' => 0, 'is_active' => true]);
    $tabTwo = Tab::factory()->for($user)->create(['position' => 1, 'is_active' => false]);
    $tabThree = Tab::factory()->for($user)->create(['position' => 2, 'is_active' => false]);

    $response = $this->actingAs($user)->postJson('/api/tabs/bulk-delete', [
        'ids' => [$tabOne->id, $tabThree->id],
        'next_active_id' => $tabTwo->id,
    ]);

    $response->assertSuccessful();
    expect($response->json('deleted_ids'))->toBe([$tabOne->id, $tabThree->id]);
    expect($response->json('active_tab_id'))->toBe($tabTwo->id);

    $this->assertDatabaseMissing('tabs', ['id' => $tabOne->id]);
    $this->assertDatabaseMissing('tabs', ['id' => $tabThree->id]);
    $this->assertDatabaseHas('tabs', ['id' => $tabTwo->id, 'is_active' => true]);
});

test('bulk delete keeps the current active tab when it survives', function () {
    $user = User::factory()->create();
    $activeTab = Tab::factory()->for($user)->create(['position' => 0, 'is_active' => true]);
    $tabToDelete = Tab::factory()->for($user)->create(['position' => 1, 'is_active' => false]);

    $response = $this->actingAs($user)->postJson('/api/tabs/bulk-delete', [
        'ids' => [$tabToDelete->id],
    ]);

    $response->assertSuccessful();
    expect($response->json('active_tab_id'))->toBe($activeTab->id);
    $this->assertDatabaseHas('tabs', ['id' => $activeTab->id, 'is_active' => true]);
});

test('bulk delete rejects foreign ids', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $tab = Tab::factory()->for($user)->create(['position' => 0]);
    $foreignTab = Tab::factory()->for($otherUser)->create(['position' => 0]);

    $response = $this->actingAs($user)->postJson('/api/tabs/bulk-delete', [
        'ids' => [$tab->id, $foreignTab->id],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('ids');
});

test('bulk delete rejects next active tab inside deleted ids', function () {
    $user = User::factory()->create();
    $tabOne = Tab::factory()->for($user)->create(['position' => 0, 'is_active' => true]);
    $tabTwo = Tab::factory()->for($user)->create(['position' => 1]);

    $response = $this->actingAs($user)->postJson('/api/tabs/bulk-delete', [
        'ids' => [$tabOne->id, $tabTwo->id],
        'next_active_id' => $tabTwo->id,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('next_active_id');
});

test('guest cannot bulk delete tabs', function () {
    $user = User::factory()->create();
    $tab = Tab::factory()->for($user)->create(['position' => 0]);

    $response = $this->postJson('/api/tabs/bulk-delete', [
        'ids' => [$tab->id],
    ]);

    $response->assertUnauthorized();
});
