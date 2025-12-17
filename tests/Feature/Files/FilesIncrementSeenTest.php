<?php

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('authenticated user can increment seen count', function () {
    $user = User::factory()->admin()->create();
    $file = File::factory()->create(['seen_count' => 0]);

    $response = $this->actingAs($user)->postJson("/api/files/{$file->id}/seen");

    $response->assertSuccessful();
    $response->assertJson([
        'message' => 'Seen count incremented.',
        'seen_count' => 1,
    ]);

    $file->refresh();
    expect($file->seen_count)->toBe(1);
});

test('increments seen count multiple times', function () {
    $user = User::factory()->admin()->create();
    $file = File::factory()->create(['seen_count' => 5]);

    $response = $this->actingAs($user)->postJson("/api/files/{$file->id}/seen");

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['seen_count'])->toBe(6);

    $file->refresh();
    expect($file->seen_count)->toBe(6);
});

test('updates seen_at timestamp', function () {
    $user = User::factory()->admin()->create();
    $file = File::factory()->create([
        'seen_count' => 0,
        'seen_at' => null,
    ]);

    $this->actingAs($user)->postJson("/api/files/{$file->id}/seen");

    $file->refresh();
    expect($file->seen_at)->not->toBeNull();
});

test('guest cannot increment seen count', function () {
    $file = File::factory()->create();

    $response = $this->postJson("/api/files/{$file->id}/seen");

    $response->assertUnauthorized();
});

test('viewing non-existent file returns 404', function () {
    $user = User::factory()->admin()->create();

    $response = $this->actingAs($user)->postJson('/api/files/99999/seen');

    $response->assertNotFound();
});

test('returns correct seen count in response', function () {
    $user = User::factory()->admin()->create();
    $file = File::factory()->create(['seen_count' => 10]);

    $response = $this->actingAs($user)->postJson("/api/files/{$file->id}/seen");

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['seen_count'])->toBe(11);
});
