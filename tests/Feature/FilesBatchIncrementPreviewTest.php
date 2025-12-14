<?php

use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('batch increments preview count for multiple files', function () {
    $admin = User::factory()->admin()->create();
    $file1 = File::factory()->create(['previewed_count' => 0]);
    $file2 = File::factory()->create(['previewed_count' => 1]);
    $file3 = File::factory()->create(['previewed_count' => 2]);

    $response = $this->actingAs($admin)->postJson('/api/files/preview/batch', [
        'file_ids' => [$file1->id, $file2->id, $file3->id],
    ]);

    $response->assertSuccessful();
    $response->assertJsonStructure([
        'message',
        'results' => [
            '*' => ['id', 'previewed_count', 'will_auto_dislike'],
        ],
    ]);

    $file1->refresh();
    $file2->refresh();
    $file3->refresh();

    expect($file1->previewed_count)->toBe(1);
    expect($file2->previewed_count)->toBe(2);
    expect($file3->previewed_count)->toBe(3);
});

test('batch returns will_auto_dislike flag for files reaching threshold', function () {
    $admin = User::factory()->admin()->create();
    $file1 = File::factory()->create([
        'previewed_count' => 2,
        'source' => 'wallhaven',
        'path' => null,
        'blacklisted_at' => null,
    ]);
    $file2 = File::factory()->create([
        'previewed_count' => 2,
        'source' => 'wallhaven',
        'path' => null,
        'blacklisted_at' => null,
    ]);

    $response = $this->actingAs($admin)->postJson('/api/files/preview/batch', [
        'file_ids' => [$file1->id, $file2->id],
    ]);

    $response->assertSuccessful();

    $results = $response->json('results');
    $file1Result = collect($results)->firstWhere('id', $file1->id);
    $file2Result = collect($results)->firstWhere('id', $file2->id);

    expect($file1Result['will_auto_dislike'])->toBeTrue();
    expect($file2Result['will_auto_dislike'])->toBeTrue();

    $file1->refresh();
    $file2->refresh();

    expect($file1->previewed_count)->toBe(3);
    expect($file2->previewed_count)->toBe(3);
    // Files are NOT auto-disliked immediately - UI handles countdown
    expect($file1->auto_disliked)->toBeFalse();
    expect($file2->auto_disliked)->toBeFalse();
});

test('batch does not return will_auto_dislike for files that already have reactions', function () {
    $admin = User::factory()->admin()->create();
    $file1 = File::factory()->create([
        'previewed_count' => 2,
        'source' => 'wallhaven',
        'path' => null,
        'blacklisted_at' => null,
    ]);
    $file2 = File::factory()->create([
        'previewed_count' => 2,
        'source' => 'wallhaven',
        'path' => null,
        'blacklisted_at' => null,
    ]);

    // File1 already has a reaction
    Reaction::create([
        'file_id' => $file1->id,
        'user_id' => $admin->id,
        'type' => 'like',
    ]);

    $response = $this->actingAs($admin)->postJson('/api/files/preview/batch', [
        'file_ids' => [$file1->id, $file2->id],
    ]);

    $response->assertSuccessful();

    $results = $response->json('results');
    $file1Result = collect($results)->firstWhere('id', $file1->id);
    $file2Result = collect($results)->firstWhere('id', $file2->id);

    // File1 should not be flagged (has reaction)
    expect($file1Result['will_auto_dislike'])->toBeFalse();
    // File2 should be flagged (no reaction)
    expect($file2Result['will_auto_dislike'])->toBeTrue();
});

test('batch handles mixed candidates correctly', function () {
    $admin = User::factory()->admin()->create();
    $file1 = File::factory()->create([
        'previewed_count' => 2,
        'source' => 'wallhaven',
        'path' => null,
        'blacklisted_at' => null,
    ]);
    $file2 = File::factory()->create([
        'previewed_count' => 1, // Not a candidate (previewed_count < 3 after increment)
        'source' => 'wallhaven',
        'path' => null,
        'blacklisted_at' => null,
    ]);
    $file3 = File::factory()->create([
        'previewed_count' => 2,
        'source' => 'local', // Not a candidate (source is local)
        'path' => null,
        'blacklisted_at' => null,
    ]);

    $response = $this->actingAs($admin)->postJson('/api/files/preview/batch', [
        'file_ids' => [$file1->id, $file2->id, $file3->id],
    ]);

    $response->assertSuccessful();

    $results = $response->json('results');
    $file1Result = collect($results)->firstWhere('id', $file1->id);
    $file2Result = collect($results)->firstWhere('id', $file2->id);
    $file3Result = collect($results)->firstWhere('id', $file3->id);

    // Only file1 should be flagged (meets all criteria)
    expect($file1Result['will_auto_dislike'])->toBeTrue();
    expect($file2Result['will_auto_dislike'])->toBeFalse();
    expect($file3Result['will_auto_dislike'])->toBeFalse();
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
