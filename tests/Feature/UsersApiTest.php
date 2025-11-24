<?php

use App\Models\User;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('returns a list of users for admin users', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    User::factory()->count(5)->create();

    $this->actingAs($admin)
        ->getJson('/api/users')
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'name',
                    'email',
                    'email_verified_at',
                    'last_login_at',
                    'created_at',
                ],
            ],
        ]);
});

it('prevents non-admin users from accessing users', function () {
    $user = User::factory()->create(['is_admin' => false]);
    User::factory()->count(5)->create();

    $this->actingAs($user)
        ->getJson('/api/users')
        ->assertForbidden();
});

it('requires authentication to access users', function () {
    $this->getJson('/api/users')
        ->assertUnauthorized();
});

it('returns users ordered by name', function () {
    $admin = User::factory()->create(['is_admin' => true, 'name' => 'Zebra User']);
    User::factory()->create(['name' => 'Alpha User']);
    User::factory()->create(['name' => 'Beta User']);

    $response = $this->actingAs($admin)
        ->getJson('/api/users');

    $response->assertSuccessful();
    $users = $response->json('data');
    expect($users[0]['name'])->toBe('Alpha User');
    expect($users[1]['name'])->toBe('Beta User');
    expect($users[2]['name'])->toBe('Zebra User');
});

it('allows admin to delete users', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $userToDelete = User::factory()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/users/{$userToDelete->id}")
        ->assertSuccessful()
        ->assertJson([
            'message' => 'User deleted successfully.',
        ]);

    expect(User::find($userToDelete->id))->toBeNull();
});

it('prevents non-admin users from deleting users', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $userToDelete = User::factory()->create();

    $this->actingAs($user)
        ->deleteJson("/api/users/{$userToDelete->id}")
        ->assertForbidden();

    expect(User::find($userToDelete->id))->not->toBeNull();
});

it('requires authentication to delete users', function () {
    $userToDelete = User::factory()->create();

    $this->deleteJson("/api/users/{$userToDelete->id}")
        ->assertUnauthorized();
});
