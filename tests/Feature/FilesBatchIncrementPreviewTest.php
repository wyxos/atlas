<?php

use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function previewBatchTestFile(array $attributes = []): File
{
    return File::factory()->create(array_merge([
        'auto_blacklisted' => false,
        'blacklisted_at' => null,
        'source' => 'Booru',
        'downloaded' => false,
        'downloaded_at' => null,
        'path' => null,
        'preview_path' => null,
        'poster_path' => null,
    ], $attributes));
}

test('batch increments preview counts and returns moderation state', function () {
    $admin = User::factory()->admin()->create();
    $file1 = previewBatchTestFile(['previewed_count' => 0]);
    $file2 = previewBatchTestFile(['previewed_count' => 1]);
    $file3 = previewBatchTestFile(['previewed_count' => 2]);

    $response = $this->actingAs($admin)->postJson('/api/files/preview/batch', [
        'file_ids' => [$file1->id, $file2->id, $file3->id],
    ]);

    $response->assertSuccessful()
        ->assertJsonStructure([
            'message',
            'results' => [
                '*' => ['id', 'previewed_count', 'reaction', 'auto_blacklisted', 'blacklisted_at'],
            ],
        ]);

    $results = collect($response->json('results'))->keyBy('id');

    expect($file1->fresh()->previewed_count)->toBe(1)
        ->and($file1->fresh()->auto_blacklisted)->toBeFalse()
        ->and($results[$file1->id]['reaction'])->toBeNull()
        ->and($file2->fresh()->previewed_count)->toBe(2)
        ->and($file2->fresh()->auto_blacklisted)->toBeTrue()
        ->and($file2->fresh()->blacklisted_at)->not->toBeNull()
        ->and($results[$file2->id]['reaction'])->toBeNull()
        ->and($file3->fresh()->previewed_count)->toBe(3)
        ->and($file3->fresh()->auto_blacklisted)->toBeTrue()
        ->and($file3->fresh()->blacklisted_at)->not->toBeNull()
        ->and($results[$file3->id]['reaction'])->toBeNull();
});

test('batch preview moves blacklisted items beyond blacklist review threshold', function () {
    $admin = User::factory()->admin()->create();
    $manualBlacklisted = previewBatchTestFile([
        'previewed_count' => 1,
        'blacklisted_at' => now()->subHour(),
    ]);
    $autoBlacklisted = previewBatchTestFile([
        'previewed_count' => 0,
        'auto_blacklisted' => true,
        'blacklisted_at' => now()->subHour(),
    ]);
    $alreadyBlacklisted = previewBatchTestFile([
        'previewed_count' => 1,
        'blacklisted_at' => now()->subHour(),
    ]);

    $response = $this->actingAs($admin)->postJson('/api/files/preview/batch', [
        'file_ids' => [$manualBlacklisted->id, $autoBlacklisted->id, $alreadyBlacklisted->id],
    ]);

    $response->assertSuccessful();
    $results = collect($response->json('results'))->keyBy('id');

    foreach ([$manualBlacklisted, $autoBlacklisted, $alreadyBlacklisted] as $file) {
        $file->refresh();

        expect($file->previewed_count)->toBe(99999)
            ->and($file->blacklisted_at)->not->toBeNull()
            ->and($results[$file->id]['reaction'])->toBeNull()
            ->and($results[$file->id]['blacklisted_at'])->not->toBeNull();
    }

    expect($manualBlacklisted->fresh()->auto_blacklisted)->toBeFalse()
        ->and($autoBlacklisted->fresh()->auto_blacklisted)->toBeTrue()
        ->and($alreadyBlacklisted->fresh()->auto_blacklisted)->toBeFalse()
        ->and(Reaction::whereIn('file_id', [$manualBlacklisted->id, $autoBlacklisted->id, $alreadyBlacklisted->id])->count())->toBe(0);
});

test('batch increment preserves positive reactions without preview moderation', function () {
    $admin = User::factory()->admin()->create();
    $file = previewBatchTestFile(['previewed_count' => 2]);
    Reaction::create([
        'file_id' => $file->id,
        'user_id' => $admin->id,
        'type' => 'love',
    ]);

    $response = $this->actingAs($admin)->postJson('/api/files/preview/batch', [
        'file_ids' => [$file->id],
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('results.0.previewed_count', 3)
        ->assertJsonPath('results.0.reaction.type', 'love')
        ->assertJsonPath('results.0.auto_blacklisted', false)
        ->assertJsonPath('results.0.blacklisted_at', null);

    $file->refresh();
    expect($file->previewed_count)->toBe(3)
        ->and($file->blacklisted_at)->toBeNull()
        ->and($file->auto_blacklisted)->toBeFalse();
});

test('batch increments preview count by a custom amount', function () {
    $admin = User::factory()->admin()->create();
    $file1 = previewBatchTestFile(['previewed_count' => 0]);
    $file2 = previewBatchTestFile(['previewed_count' => 2]);

    $response = $this->actingAs($admin)->postJson('/api/files/preview/batch', [
        'file_ids' => [$file1->id, $file2->id],
        'increments' => 4,
    ]);

    $response->assertSuccessful();

    expect($file1->fresh()->previewed_count)->toBe(4)
        ->and($file1->fresh()->auto_blacklisted)->toBeTrue()
        ->and($file2->fresh()->previewed_count)->toBe(6)
        ->and($file2->fresh()->auto_blacklisted)->toBeTrue();
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
    $file = previewBatchTestFile();

    $response = $this->actingAs($admin)->postJson('/api/files/preview/batch', [
        'file_ids' => [$file->id],
        'increments' => 0,
    ]);

    $response->assertUnprocessable();
});
