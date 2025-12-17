<?php

use App\Models\Container;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('authenticated user can load full item data for file IDs', function () {
    $user = User::factory()->admin()->create();
    $file1 = File::factory()->create([
        'referrer_url' => 'https://example.com/file1.jpg',
        'url' => 'https://example.com/original1.jpg',
        'thumbnail_url' => 'https://example.com/thumb1.jpg',
    ]);
    $file2 = File::factory()->create([
        'referrer_url' => 'https://example.com/file2.jpg',
        'url' => 'https://example.com/original2.jpg',
    ]);

    $response = $this->actingAs($user)->postJson('/api/browse/items', [
        'ids' => [$file1->id, $file2->id],
    ]);

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['items'])->toBeArray();
    expect($data['items'])->toHaveKey((string) $file1->id);
    expect($data['items'])->toHaveKey((string) $file2->id);
    expect($data['items'][$file1->id]['id'])->toBe($file1->id);
    expect($data['items'][$file2->id]['id'])->toBe($file2->id);
});

test('returns items keyed by ID', function () {
    $user = User::factory()->admin()->create();
    $file1 = File::factory()->create();
    $file2 = File::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/browse/items', [
        'ids' => [$file2->id, $file1->id],
    ]);

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['items'])->toBeArray();
    // Should be keyed by ID, not by array index
    expect($data['items'])->toHaveKey((string) $file1->id);
    expect($data['items'])->toHaveKey((string) $file2->id);
});

test('validates ids is required', function () {
    $user = User::factory()->admin()->create();

    $response = $this->actingAs($user)->postJson('/api/browse/items', []);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('ids');
});

test('validates ids is array', function () {
    $user = User::factory()->admin()->create();

    $response = $this->actingAs($user)->postJson('/api/browse/items', [
        'ids' => 'not-an-array',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('ids');
});

test('validates ids are integers', function () {
    $user = User::factory()->admin()->create();

    $response = $this->actingAs($user)->postJson('/api/browse/items', [
        'ids' => ['not-an-integer'],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('ids.0');
});

test('validates ids is not empty', function () {
    $user = User::factory()->admin()->create();

    $response = $this->actingAs($user)->postJson('/api/browse/items', [
        'ids' => [],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('ids');
});

test('includes containers in item data', function () {
    $user = User::factory()->admin()->create();
    $file = File::factory()->create();
    $container = Container::factory()->create();
    $file->containers()->attach($container->id);

    $response = $this->actingAs($user)->postJson('/api/browse/items', [
        'ids' => [$file->id],
    ]);

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['items'][$file->id])->toHaveKey('containers');
});

test('guest cannot load item data', function () {
    $file = File::factory()->create();

    $response = $this->postJson('/api/browse/items', [
        'ids' => [$file->id],
    ]);

    $response->assertUnauthorized();
});
