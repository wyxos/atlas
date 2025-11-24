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
            'links',
            'meta' => [
                'current_page',
                'total',
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

it('supports pagination', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    User::factory()->count(20)->create();

    $response = $this->actingAs($admin)
        ->getJson('/api/users?page=1&per_page=15');

    $response->assertSuccessful();
    expect($response->json('data'))->toHaveCount(15);
    expect($response->json('meta.current_page'))->toBe(1);
    expect($response->json('meta.total'))->toBeGreaterThanOrEqual(21); // 20 + admin
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

it('filters users by search query', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    User::factory()->create(['name' => 'John Doe', 'email' => 'john@example.com']);
    User::factory()->create(['name' => 'Jane Smith', 'email' => 'jane@example.com']);

    $response = $this->actingAs($admin)
        ->getJson('/api/users?search=john');

    $response->assertSuccessful();
    $users = $response->json('data');
    expect($users)->toHaveCount(1);
    expect($users[0]['name'])->toBe('John Doe');
});

it('filters users by email search', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    User::factory()->create(['name' => 'John Doe', 'email' => 'john@example.com']);
    User::factory()->create(['name' => 'Jane Smith', 'email' => 'jane@example.com']);

    $response = $this->actingAs($admin)
        ->getJson('/api/users?search=jane@example.com');

    $response->assertSuccessful();
    $users = $response->json('data');
    expect($users)->toHaveCount(1);
    expect($users[0]['email'])->toBe('jane@example.com');
});

it('filters users by date range', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    User::factory()->create(['created_at' => '2024-01-15 10:00:00']);
    User::factory()->create(['created_at' => '2024-02-15 10:00:00']);
    User::factory()->create(['created_at' => '2024-03-15 10:00:00']);

    $response = $this->actingAs($admin)
        ->getJson('/api/users?date_from=2024-02-01&date_to=2024-02-28');

    $response->assertSuccessful();
    $users = $response->json('data');
    expect($users)->toHaveCount(1);
    expect($users[0]['created_at'])->toContain('2024-02-15');
});

it('filters users by verified status', function () {
    $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
    User::factory()->create(['email_verified_at' => now()]);
    User::factory()->create(['email_verified_at' => null]);
    User::factory()->create(['email_verified_at' => now()]);

    $response = $this->actingAs($admin)
        ->getJson('/api/users?status=verified');

    $response->assertSuccessful();
    $users = $response->json('data');
    // Admin + 2 verified users = 3 total
    expect($users)->toHaveCount(3);
    foreach ($users as $user) {
        expect($user['email_verified_at'])->not->toBeNull();
    }
});

it('filters users by unverified status', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    User::factory()->create(['email_verified_at' => now()]);
    User::factory()->create(['email_verified_at' => null]);
    User::factory()->create(['email_verified_at' => null]);

    $response = $this->actingAs($admin)
        ->getJson('/api/users?status=unverified');

    $response->assertSuccessful();
    $users = $response->json('data');
    expect($users)->toHaveCount(2);
    foreach ($users as $user) {
        expect($user['email_verified_at'])->toBeNull();
    }
});

it('combines multiple filters', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    User::factory()->create([
        'name' => 'John Verified',
        'email' => 'john@example.com',
        'email_verified_at' => now(),
        'created_at' => '2024-01-15 10:00:00',
    ]);
    User::factory()->create([
        'name' => 'John Unverified',
        'email' => 'john2@example.com',
        'email_verified_at' => null,
        'created_at' => '2024-01-15 10:00:00',
    ]);
    User::factory()->create([
        'name' => 'Jane Verified',
        'email' => 'jane@example.com',
        'email_verified_at' => now(),
        'created_at' => '2024-02-15 10:00:00',
    ]);

    $response = $this->actingAs($admin)
        ->getJson('/api/users?search=john&status=verified&date_from=2024-01-01&date_to=2024-01-31');

    $response->assertSuccessful();
    $users = $response->json('data');
    expect($users)->toHaveCount(1);
    expect($users[0]['name'])->toBe('John Verified');
    expect($users[0]['email_verified_at'])->not->toBeNull();
});
