<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('admin can delete another user', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $response = $this->actingAs($admin)->deleteJson("/api/users/{$user->id}");

    $response->assertSuccessful();
    $response->assertJson([
        'message' => 'User deleted successfully.',
    ]);
    $this->assertDatabaseMissing('users', ['id' => $user->id]);
});

test('admin cannot delete themselves', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->deleteJson("/api/users/{$admin->id}");

    $response->assertForbidden();
    $this->assertDatabaseHas('users', ['id' => $admin->id]);
});

test('regular user cannot delete users', function () {
    $user = User::factory()->create();
    $targetUser = User::factory()->create();

    $response = $this->actingAs($user)->deleteJson("/api/users/{$targetUser->id}");

    $response->assertForbidden();
    $this->assertDatabaseHas('users', ['id' => $targetUser->id]);
});

test('guest cannot delete users', function () {
    $user = User::factory()->create();

    $response = $this->deleteJson("/api/users/{$user->id}");

    $response->assertUnauthorized();
    $this->assertDatabaseHas('users', ['id' => $user->id]);
});

test('deleting user returns success message', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $response = $this->actingAs($admin)->deleteJson("/api/users/{$user->id}");

    $response->assertJson([
        'message' => 'User deleted successfully.',
    ]);
});

test('deleting non-existent user returns 404', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->deleteJson('/api/users/99999');

    $response->assertNotFound();
});

test('deleted user is removed from database', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $userId = $user->id;

    $this->actingAs($admin)->deleteJson("/api/users/{$userId}");

    $this->assertDatabaseMissing('users', ['id' => $userId]);
});

