<?php

use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('authenticated user can batch get reactions for multiple files', function () {
    $user = User::factory()->admin()->create();
    $file1 = File::factory()->create();
    $file2 = File::factory()->create();
    $file3 = File::factory()->create();

    Reaction::create([
        'file_id' => $file1->id,
        'user_id' => $user->id,
        'type' => 'like',
    ]);
    Reaction::create([
        'file_id' => $file2->id,
        'user_id' => $user->id,
        'type' => 'love',
    ]);
    // file3 has no reaction

    $response = $this->actingAs($user)->postJson('/api/files/reactions/batch', [
        'file_ids' => [$file1->id, $file2->id, $file3->id],
    ]);

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['reactions'])->toBeArray();
    expect(count($data['reactions']))->toBe(3);

    $file1Reaction = collect($data['reactions'])->firstWhere('file_id', $file1->id);
    $file2Reaction = collect($data['reactions'])->firstWhere('file_id', $file2->id);
    $file3Reaction = collect($data['reactions'])->firstWhere('file_id', $file3->id);

    expect($file1Reaction['reaction']['type'])->toBe('like');
    expect($file2Reaction['reaction']['type'])->toBe('love');
    expect($file3Reaction['reaction'])->toBeNull();
});

test('validates file_ids is required', function () {
    $user = User::factory()->admin()->create();

    $response = $this->actingAs($user)->postJson('/api/files/reactions/batch', []);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('file_ids');
});

test('validates file_ids is array', function () {
    $user = User::factory()->admin()->create();

    $response = $this->actingAs($user)->postJson('/api/files/reactions/batch', [
        'file_ids' => 'not-an-array',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('file_ids');
});

test('validates file_ids exist', function () {
    $user = User::factory()->admin()->create();

    $response = $this->actingAs($user)->postJson('/api/files/reactions/batch', [
        'file_ids' => [99999, 99998],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('file_ids.0');
});

test('validates file_ids is not empty', function () {
    $user = User::factory()->admin()->create();

    $response = $this->actingAs($user)->postJson('/api/files/reactions/batch', [
        'file_ids' => [],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('file_ids');
});

test('guest cannot batch get reactions', function () {
    $file = File::factory()->create();

    $response = $this->postJson('/api/files/reactions/batch', [
        'file_ids' => [$file->id],
    ]);

    $response->assertUnauthorized();
});

test('returns reactions in same order as file_ids', function () {
    $user = User::factory()->admin()->create();
    $file1 = File::factory()->create();
    $file2 = File::factory()->create();
    $file3 = File::factory()->create();

    Reaction::create([
        'file_id' => $file1->id,
        'user_id' => $user->id,
        'type' => 'like',
    ]);

    $response = $this->actingAs($user)->postJson('/api/files/reactions/batch', [
        'file_ids' => [$file3->id, $file1->id, $file2->id],
    ]);

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['reactions'][0]['file_id'])->toBe($file3->id);
    expect($data['reactions'][1]['file_id'])->toBe($file1->id);
    expect($data['reactions'][2]['file_id'])->toBe($file2->id);
});
