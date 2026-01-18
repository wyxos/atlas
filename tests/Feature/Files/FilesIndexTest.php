<?php

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('admin can view files listing', function () {
    $admin = User::factory()->admin()->create();
    File::factory()->count(5)->create();

    $response = $this->actingAs($admin)->getJson('/api/files');

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

test('admin can filter files by search filename', function () {
    $admin = User::factory()->admin()->create();
    File::factory()->create(['filename' => 'test-image.jpg']);
    File::factory()->create(['filename' => 'other-file.png']);

    $response = $this->actingAs($admin)->getJson('/api/files?search=test-image');

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['listing']['items'])->toHaveCount(1);
});

test('admin can filter files by search title', function () {
    $admin = User::factory()->admin()->create();
    File::factory()->create(['title' => 'My Test Image']);
    File::factory()->create(['title' => 'Another File']);

    $response = $this->actingAs($admin)->getJson('/api/files?search=My Test');

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['listing']['items'])->toHaveCount(1);
});

test('admin can filter files by search source', function () {
    $admin = User::factory()->admin()->create();
    File::factory()->create(['source' => 'local']);
    File::factory()->create(['source' => 'YouTube']);

    $response = $this->actingAs($admin)->getJson('/api/files?search=local');

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['listing']['items'])->toHaveCount(1);
});

test('admin can filter files by date range', function () {
    $admin = User::factory()->admin()->create();
    File::factory()->create(['created_at' => now()->subDays(5)]);
    File::factory()->create(['created_at' => now()->subDays(2)]);

    $response = $this->actingAs($admin)->getJson('/api/files?date_from='.now()->subDays(3)->format('Y-m-d'));

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['listing']['items'])->toHaveCount(1);
});

test('admin can filter files by source', function () {
    $admin = User::factory()->admin()->create();
    File::factory()->create(['source' => 'local']);
    File::factory()->create(['source' => 'YouTube']);

    $response = $this->actingAs($admin)->getJson('/api/files?source=local');

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['listing']['items'])->toHaveCount(1);
    expect($data['listing']['items'][0]['source'])->toBe('local');
});

test('admin can filter files by MIME type', function () {
    $admin = User::factory()->admin()->create();
    File::factory()->create(['mime_type' => 'image/jpeg']);
    File::factory()->create(['mime_type' => 'video/mp4']);

    $response = $this->actingAs($admin)->getJson('/api/files?mime_type=image');

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['listing']['items'])->toHaveCount(1);
});

test('admin can filter files by downloaded status', function () {
    $admin = User::factory()->admin()->create();
    File::factory()->create(['downloaded' => true]);
    File::factory()->create(['downloaded' => false]);

    $response = $this->actingAs($admin)->getJson('/api/files?downloaded=yes');

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['listing']['items'])->toHaveCount(1);
    expect($data['listing']['items'][0]['downloaded'])->toBeTrue();
});

test('admin receives paginated files listing', function () {
    $admin = User::factory()->admin()->create();
    File::factory()->count(20)->create();

    $response = $this->actingAs($admin)->getJson('/api/files?per_page=10');

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['listing']['items'])->toHaveCount(10);
    expect($data['listing']['perPage'])->toBe(10);
});

test('regular user can view files listing', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/files');

    $response->assertSuccessful();
});

test('guest cannot view files listing', function () {
    $response = $this->getJson('/api/files');

    $response->assertUnauthorized();
});

test('files listing returns correct JSON structure with FileResource', function () {
    $admin = User::factory()->admin()->create();
    File::factory()->create();

    $response = $this->actingAs($admin)->getJson('/api/files');

    $response->assertSuccessful();
    $response->assertJsonStructure([
        'listing' => [
            'items' => [
                '*' => [
                    'id',
                ],
            ],
            'total',
            'perPage',
            'current_page',
            'last_page',
        ],
        'filters',
    ]);
});

test('files listing includes filter metadata', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->getJson('/api/files?search=test&source=local');

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['filters'])->toBeArray();
});
