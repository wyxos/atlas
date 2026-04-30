<?php

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('batch increments preview count for multiple files without auto dislike state', function () {
    $admin = User::factory()->admin()->create();
    $file1 = File::factory()->create(['previewed_count' => 0]);
    $file2 = File::factory()->create(['previewed_count' => 1]);
    $file3 = File::factory()->create(['previewed_count' => 2]);

    $response = $this->actingAs($admin)->postJson('/api/files/preview/batch', [
        'file_ids' => [$file1->id, $file2->id, $file3->id],
    ]);

    $response->assertSuccessful()
        ->assertJsonStructure([
            'message',
            'results' => [
                '*' => ['id', 'previewed_count'],
            ],
        ])
        ->assertJsonMissingPath('results.0.will_auto_dislike');

    expect($file1->fresh()->previewed_count)->toBe(1)
        ->and($file2->fresh()->previewed_count)->toBe(2)
        ->and($file3->fresh()->previewed_count)->toBe(3);
});

test('batch increments preview count by a custom amount', function () {
    $admin = User::factory()->admin()->create();
    $file1 = File::factory()->create(['previewed_count' => 0]);
    $file2 = File::factory()->create(['previewed_count' => 2]);

    $response = $this->actingAs($admin)->postJson('/api/files/preview/batch', [
        'file_ids' => [$file1->id, $file2->id],
        'increments' => 4,
    ]);

    $response->assertSuccessful();

    expect($file1->fresh()->previewed_count)->toBe(4)
        ->and($file2->fresh()->previewed_count)->toBe(6);
});

test('batch validates file_ids array', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->postJson('/api/files/preview/batch', [
        'file_ids' => 'not-an-array',
    ]);

    $response->assertUnprocessable();
});

test('batch validates file_ids exist', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->postJson('/api/files/preview/batch', [
        'file_ids' => [99999, 99998],
    ]);

    $response->assertUnprocessable();
});

test('batch validates increments when provided', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create();

    $response = $this->actingAs($admin)->postJson('/api/files/preview/batch', [
        'file_ids' => [$file->id],
        'increments' => 0,
    ]);

    $response->assertUnprocessable();
});
