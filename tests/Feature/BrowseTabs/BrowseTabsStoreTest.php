<?php

use App\Models\BrowseTab;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('authenticated user can create browse tab', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/browse-tabs', [
        'label' => 'My Tab',
    ]);

    $response->assertStatus(201);
    $response->assertJsonStructure([
        'id',
        'label',
        'user_id',
        'position',
    ]);
    $this->assertDatabaseHas('browse_tabs', [
        'label' => 'My Tab',
        'user_id' => $user->id,
    ]);
});

test('tab creation requires label', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/browse-tabs', []);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('label');
});

test('tab creation accepts optional query_params', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/browse-tabs', [
        'label' => 'My Tab',
        'query_params' => ['page' => 2, 'filter' => 'test'],
    ]);

    $response->assertStatus(201);
    $data = $response->json();
    expect($data['query_params'])->toBe(['page' => 2, 'filter' => 'test']);
});

test('tab creation accepts optional file_ids', function () {
    $user = User::factory()->create();
    $file1 = File::factory()->create();
    $file2 = File::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/browse-tabs', [
        'label' => 'My Tab',
        'file_ids' => [$file1->id, $file2->id],
    ]);

    $response->assertStatus(201);
    $data = $response->json();
    expect($data['file_ids'])->toBe([$file1->id, $file2->id]);
    
    // Verify files are attached via relationship
    $tab = BrowseTab::find($data['id']);
    expect($tab->files)->toHaveCount(2);
    expect($tab->files->pluck('id')->toArray())->toBe([$file1->id, $file2->id]);
});

test('tab creation accepts optional position', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/browse-tabs', [
        'label' => 'My Tab',
        'position' => 5,
    ]);

    $response->assertStatus(201);
    $data = $response->json();
    expect($data['position'])->toBe(5);
});

test('tab creation auto-assigns position when not provided max plus one', function () {
    $user = User::factory()->create();
    BrowseTab::factory()->for($user)->create(['position' => 2]);
    BrowseTab::factory()->for($user)->create(['position' => 5]);

    $response = $this->actingAs($user)->postJson('/api/browse-tabs', [
        'label' => 'My Tab',
    ]);

    $response->assertStatus(201);
    $data = $response->json();
    expect($data['position'])->toBe(6); // max(2, 5) + 1
});

test('tab creation assigns position 0 when no existing tabs', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/browse-tabs', [
        'label' => 'My Tab',
    ]);

    $response->assertStatus(201);
    $data = $response->json();
    expect($data['position'])->toBe(0);
});

test('tab creation returns 201 status', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/browse-tabs', [
        'label' => 'My Tab',
    ]);

    $response->assertStatus(201);
});

test('tab creation returns created tab in response', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/browse-tabs', [
        'label' => 'My Tab',
    ]);

    $response->assertStatus(201);
    $data = $response->json();
    expect($data['label'])->toBe('My Tab');
    expect($data['user_id'])->toBe($user->id);
});

test('tab is associated with authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/browse-tabs', [
        'label' => 'My Tab',
    ]);

    $this->assertDatabaseHas('browse_tabs', [
        'label' => 'My Tab',
        'user_id' => $user->id,
    ]);
});

test('guest cannot create browse tabs', function () {
    $response = $this->postJson('/api/browse-tabs', [
        'label' => 'My Tab',
    ]);

    $response->assertUnauthorized();
});

test('validation fails when label is missing', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/browse-tabs', []);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('label');
});

test('validation fails when label exceeds max length', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/browse-tabs', [
        'label' => str_repeat('a', 256),
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('label');
});

test('validation fails when position is negative', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/browse-tabs', [
        'label' => 'My Tab',
        'position' => -1,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('position');
});

test('validation fails when query_params is not array', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/browse-tabs', [
        'label' => 'My Tab',
        'query_params' => 'not-an-array',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('query_params');
});

test('validation fails when file_ids is not array', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/browse-tabs', [
        'label' => 'My Tab',
        'file_ids' => 'not-an-array',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('file_ids');
});

test('validation fails when file_ids contains non-existent file', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/browse-tabs', [
        'label' => 'My Tab',
        'file_ids' => [99999], // Non-existent file ID
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('file_ids.0');
});

test('validation fails when file_ids contains non-integer', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/browse-tabs', [
        'label' => 'My Tab',
        'file_ids' => ['not-an-integer'],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('file_ids.0');
});

