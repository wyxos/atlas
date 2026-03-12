<?php

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('batch resets preview count for multiple files', function () {
    $admin = User::factory()->admin()->create();
    $file1 = File::factory()->create([
        'previewed_count' => 3,
        'previewed_at' => now()->subHour(),
    ]);
    $file2 = File::factory()->create([
        'previewed_count' => 1,
        'previewed_at' => now()->subMinutes(30),
    ]);

    $response = $this->actingAs($admin)->postJson('/api/files/preview/reset/batch', [
        'file_ids' => [$file1->id, $file2->id],
    ]);

    $response->assertSuccessful();
    $response->assertJsonStructure([
        'message',
        'results' => [
            '*' => ['id', 'previewed_count'],
        ],
    ]);

    $file1->refresh();
    $file2->refresh();

    expect($file1->previewed_count)->toBe(0)
        ->and($file2->previewed_count)->toBe(0)
        ->and($file1->previewed_at)->toBeNull()
        ->and($file2->previewed_at)->toBeNull();
});

test('batch reset preview validates file_ids array', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->postJson('/api/files/preview/reset/batch', [
        'file_ids' => 'not-an-array',
    ]);

    $response->assertUnprocessable();
});
