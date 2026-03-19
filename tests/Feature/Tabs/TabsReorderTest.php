<?php

use App\Models\Tab;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('user can reorder their tabs and positions are compacted', function () {
    $user = User::factory()->create();
    $tabOne = Tab::factory()->for($user)->create(['position' => 4]);
    $tabTwo = Tab::factory()->for($user)->create(['position' => 8]);
    $tabThree = Tab::factory()->for($user)->create(['position' => 12]);

    $response = $this->actingAs($user)->postJson('/api/tabs/reorder', [
        'ordered_ids' => [$tabThree->id, $tabOne->id, $tabTwo->id],
    ]);

    $response->assertSuccessful();
    expect($response->json('ordered_ids'))->toBe([$tabThree->id, $tabOne->id, $tabTwo->id]);

    $ordered = Tab::forUser($user->id)->ordered()->pluck('id')->all();
    expect($ordered)->toBe([$tabThree->id, $tabOne->id, $tabTwo->id]);

    $this->assertDatabaseHas('tabs', ['id' => $tabThree->id, 'position' => 0]);
    $this->assertDatabaseHas('tabs', ['id' => $tabOne->id, 'position' => 1]);
    $this->assertDatabaseHas('tabs', ['id' => $tabTwo->id, 'position' => 2]);
});

test('reorder rejects missing or stale ids', function () {
    $user = User::factory()->create();
    $tabOne = Tab::factory()->for($user)->create(['position' => 0]);
    $tabTwo = Tab::factory()->for($user)->create(['position' => 1]);

    $response = $this->actingAs($user)->postJson('/api/tabs/reorder', [
        'ordered_ids' => [$tabOne->id],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('ordered_ids');

    $ordered = Tab::forUser($user->id)->ordered()->pluck('id')->all();
    expect($ordered)->toBe([$tabOne->id, $tabTwo->id]);
});

test('reorder rejects foreign tab ids', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $tabOne = Tab::factory()->for($user)->create(['position' => 0]);
    $tabTwo = Tab::factory()->for($user)->create(['position' => 1]);
    $foreignTab = Tab::factory()->for($otherUser)->create(['position' => 0]);

    $response = $this->actingAs($user)->postJson('/api/tabs/reorder', [
        'ordered_ids' => [$tabOne->id, $foreignTab->id, $tabTwo->id],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('ordered_ids');
});

test('guest cannot reorder tabs', function () {
    $user = User::factory()->create();
    $tabOne = Tab::factory()->for($user)->create(['position' => 0]);

    $response = $this->postJson('/api/tabs/reorder', [
        'ordered_ids' => [$tabOne->id],
    ]);

    $response->assertUnauthorized();
});
