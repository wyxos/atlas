<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('users index page can be rendered for admin', function () {
    $user = User::factory()->create(['is_admin' => true]);

    $response = $this->actingAs($user)->get('/users');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('Users/Index'));
});

test('users index page cannot be accessed by non-admin', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $response = $this->actingAs($user)->get('/users');

    $response->assertStatus(403);
});

test('user edit page can be rendered for admin', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $userToEdit = User::factory()->create();

    $response = $this->actingAs($admin)->get("/users/{$userToEdit->id}/edit");

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) =>
        $page->component('Users/Edit')
             ->has('user')
    );
});

test('user edit page cannot be accessed by non-admin', function () {
    $regularUser = User::factory()->create(['is_admin' => false]);
    $userToEdit = User::factory()->create();

    $response = $this->actingAs($regularUser)->get("/users/{$userToEdit->id}/edit");

    $response->assertStatus(403);
});

test('user can be updated by admin', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $userToUpdate = User::factory()->create();

    $updatedData = [
        'name' => 'Updated Name',
        'email' => 'updated@example.com',
    ];

    $response = $this->actingAs($admin)->put("/users/{$userToUpdate->id}", $updatedData);

    $response->assertRedirect('/users');
    $response->assertSessionHas('success');

    $this->assertDatabaseHas('users', [
        'id' => $userToUpdate->id,
        'name' => 'Updated Name',
        'email' => 'updated@example.com',
    ]);
});

test('user cannot be updated by non-admin', function () {
    $regularUser = User::factory()->create(['is_admin' => false]);
    $userToUpdate = User::factory()->create();

    $updatedData = [
        'name' => 'Updated Name',
        'email' => 'updated@example.com',
    ];

    $response = $this->actingAs($regularUser)->put("/users/{$userToUpdate->id}", $updatedData);

    $response->assertStatus(403);

    $this->assertDatabaseMissing('users', [
        'id' => $userToUpdate->id,
        'name' => 'Updated Name',
        'email' => 'updated@example.com',
    ]);
});

test('user can be deleted by admin', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $userToDelete = User::factory()->create();

    $response = $this->actingAs($admin)->delete("/users/{$userToDelete->id}");

    $response->assertRedirect('/users');
    $response->assertSessionHas('success');

    $this->assertDatabaseMissing('users', [
        'id' => $userToDelete->id,
    ]);
});

test('user cannot be deleted by non-admin', function () {
    $regularUser = User::factory()->create(['is_admin' => false]);
    $userToDelete = User::factory()->create();

    $response = $this->actingAs($regularUser)->delete("/users/{$userToDelete->id}");

    $response->assertStatus(403);

    $this->assertDatabaseHas('users', [
        'id' => $userToDelete->id,
    ]);
});

test('user cannot delete themselves', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $response = $this->actingAs($admin)->delete("/users/{$admin->id}");

    $response->assertRedirect('/users');
    $response->assertSessionHas('error');

    $this->assertDatabaseHas('users', [
        'id' => $admin->id,
    ]);
});

test('non-admin cannot delete admin', function () {
    $regularUser = User::factory()->create(['is_admin' => false]);
    $admin = User::factory()->create(['is_admin' => true]);

    $response = $this->actingAs($regularUser)->delete("/users/{$admin->id}");

    $response->assertStatus(403);

    $this->assertDatabaseHas('users', [
        'id' => $admin->id,
    ]);
});
