<?php

use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('authenticated user can get their reaction for a file', function () {
    $user = User::factory()->admin()->create();
    $file = File::factory()->create();
    $reaction = Reaction::create([
        'file_id' => $file->id,
        'user_id' => $user->id,
        'type' => 'like',
    ]);

    $response = $this->actingAs($user)->getJson("/api/files/{$file->id}/reaction");

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['reaction'])->not->toBeNull();
    expect($data['reaction']['type'])->toBe('like');
});

test('returns null when user has no reaction', function () {
    $user = User::factory()->admin()->create();
    $file = File::factory()->create();

    $response = $this->actingAs($user)->getJson("/api/files/{$file->id}/reaction");

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['reaction'])->toBeNull();
});

test('returns correct reaction type', function () {
    $user = User::factory()->admin()->create();
    $file = File::factory()->create();
    Reaction::create([
        'file_id' => $file->id,
        'user_id' => $user->id,
        'type' => 'love',
    ]);

    $response = $this->actingAs($user)->getJson("/api/files/{$file->id}/reaction");

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['reaction']['type'])->toBe('love');
});

test('guest cannot get file reaction', function () {
    $file = File::factory()->create();

    $response = $this->getJson("/api/files/{$file->id}/reaction");

    $response->assertUnauthorized();
});

test('viewing non-existent file returns 404', function () {
    $user = User::factory()->admin()->create();

    $response = $this->actingAs($user)->getJson('/api/files/99999/reaction');

    $response->assertNotFound();
});
