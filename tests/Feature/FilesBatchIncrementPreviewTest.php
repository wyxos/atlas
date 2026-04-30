<?php

use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function previewBatchTestFile(array $attributes = []): File
{
    return File::factory()->create(array_merge([
        'auto_disliked' => false,
        'blacklisted_at' => null,
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
                '*' => ['id', 'previewed_count', 'reaction', 'auto_disliked', 'blacklisted_at'],
            ],
        ]);

    $results = collect($response->json('results'))->keyBy('id');

    expect($file1->fresh()->previewed_count)->toBe(1)
        ->and($file1->fresh()->auto_disliked)->toBeFalse()
        ->and($results[$file1->id]['reaction'])->toBeNull()
        ->and($file2->fresh()->previewed_count)->toBe(2)
        ->and($file2->fresh()->auto_disliked)->toBeTrue()
        ->and($results[$file2->id]['reaction'])->toBe(['type' => 'dislike'])
        ->and($file3->fresh()->previewed_count)->toBe(3)
        ->and($file3->fresh()->auto_disliked)->toBeTrue()
        ->and($results[$file3->id]['reaction'])->toBe(['type' => 'dislike']);
});

test('batch preview blacklists manual disliked auto disliked and already blacklisted items', function () {
    $admin = User::factory()->admin()->create();
    $manualDisliked = previewBatchTestFile(['previewed_count' => 2]);
    $autoDisliked = previewBatchTestFile([
        'previewed_count' => 0,
        'auto_disliked' => true,
    ]);
    $alreadyBlacklisted = previewBatchTestFile([
        'previewed_count' => 1,
        'blacklisted_at' => now()->subHour(),
    ]);
    Reaction::create([
        'file_id' => $manualDisliked->id,
        'user_id' => $admin->id,
        'type' => 'dislike',
    ]);
    Reaction::create([
        'file_id' => $autoDisliked->id,
        'user_id' => $admin->id,
        'type' => 'dislike',
    ]);

    $response = $this->actingAs($admin)->postJson('/api/files/preview/batch', [
        'file_ids' => [$manualDisliked->id, $autoDisliked->id, $alreadyBlacklisted->id],
    ]);

    $response->assertSuccessful();
    $results = collect($response->json('results'))->keyBy('id');

    foreach ([$manualDisliked, $autoDisliked, $alreadyBlacklisted] as $file) {
        $file->refresh();

        expect($file->previewed_count)->toBe(4)
            ->and($file->blacklisted_at)->not->toBeNull()
            ->and($file->auto_disliked)->toBeFalse()
            ->and($results[$file->id]['reaction'])->toBeNull()
            ->and($results[$file->id]['blacklisted_at'])->not->toBeNull();
    }

    expect(Reaction::whereIn('file_id', [$manualDisliked->id, $autoDisliked->id, $alreadyBlacklisted->id])->count())->toBe(0);
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
        ->assertJsonPath('results.0.auto_disliked', false)
        ->assertJsonPath('results.0.blacklisted_at', null);

    $file->refresh();
    expect($file->previewed_count)->toBe(3)
        ->and($file->blacklisted_at)->toBeNull()
        ->and($file->auto_disliked)->toBeFalse();
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
        ->and($file1->fresh()->auto_disliked)->toBeTrue()
        ->and($file2->fresh()->previewed_count)->toBe(6)
        ->and($file2->fresh()->auto_disliked)->toBeTrue();
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
