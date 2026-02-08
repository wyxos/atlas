<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('admin can view users listing', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->count(5)->create();

    $response = $this->actingAs($admin)->getJson('/api/users');

    $response->assertSuccessful();
    $response->assertJsonStructure([
        'listing' => [
            'items',
            'total',
            'perPage',
            'current_page',
            'last_page',
        ],
        'filters',
    ]);
});

test('admin can filter users by search name', function () {
    // Set explicit name/email for admin to avoid random matches with search term
    $admin = User::factory()->admin()->create([
        'name' => 'Admin User',
        'email' => 'admin@example.com',
    ]);
    User::factory()->create(['name' => 'John Doe', 'email' => 'john.doe@example.com']);
    User::factory()->create(['name' => 'Jane Smith', 'email' => 'jane.smith@example.com']);

    $response = $this->actingAs($admin)->getJson('/api/users?search=John');

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['listing']['items'])->toHaveCount(1);
    expect($data['listing']['items'][0]['name'])->toContain('John');
});

test('admin can filter users by search email', function () {
    $admin = User::factory()->admin()->create([
        'name' => 'Admin User',
        'email' => 'admin@example.com',
    ]);
    User::factory()->create(['email' => 'john@example.com']);
    User::factory()->create(['email' => 'jane@example.com']);

    $response = $this->actingAs($admin)->getJson('/api/users?search=john@example.com');

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['listing']['items'])->toHaveCount(1);
});

test('admin can filter users by date range', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->create(['created_at' => now()->subDays(5)]);
    User::factory()->create(['created_at' => now()->subDays(2)]);

    $response = $this->actingAs($admin)->getJson('/api/users?date_from='.now()->subDays(3)->format('Y-m-d'));

    $response->assertSuccessful();
    $data = $response->json();
    // Should include admin (created now) and the user created 2 days ago
    expect($data['listing']['items'])->toHaveCount(2);
});

test('admin can filter users by verification status', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->create(['email_verified_at' => now()]);
    User::factory()->unverified()->create();

    $response = $this->actingAs($admin)->getJson('/api/users?status=verified');

    $response->assertSuccessful();
    $data = $response->json();
    // Should include admin (verified) and the verified user
    expect($data['listing']['items'])->toHaveCount(2);
    foreach ($data['listing']['items'] as $item) {
        expect($item['email_verified_at'])->not->toBeNull();
    }
});

test('admin receives paginated users listing', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->count(20)->create();

    $response = $this->actingAs($admin)->getJson('/api/users?per_page=10');

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['listing']['items'])->toHaveCount(10);
    expect($data['listing']['perPage'])->toBe(10);
    expect($data['listing']['total'])->toBe(21); // 20 + admin
});

test('regular user can view users listing', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/users');

    $response->assertSuccessful();
});

test('guest cannot view users listing', function () {
    $response = $this->getJson('/api/users');

    $response->assertUnauthorized();
});

test('users listing returns correct JSON structure', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->create();

    $response = $this->actingAs($admin)->getJson('/api/users');

    $response->assertSuccessful();
    $response->assertJsonStructure([
        'listing' => [
            'items' => [
                '*' => [
                    'id',
                    'name',
                    'email',
                ],
            ],
            'total',
            'perPage',
            'current_page',
            'last_page',
            'from',
            'to',
        ],
        'links',
        'filters',
    ]);
});

test('users listing includes filter metadata', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->getJson('/api/users?search=test&status=verified');

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['filters'])->toBeArray();
});
