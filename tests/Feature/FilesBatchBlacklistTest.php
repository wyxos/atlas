<?php

use App\Models\File;
use App\Models\Reaction;
use App\Models\Tab;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('batch blacklists loaded files and detaches them from the current users tabs', function () {
    $admin = User::factory()->admin()->create();
    $file1 = File::factory()->create(['blacklisted_at' => null]);
    $file2 = File::factory()->create(['blacklisted_at' => null]);
    $tab = Tab::factory()
        ->for($admin)
        ->withFiles([$file1->id, $file2->id])
        ->create();

    $response = $this->actingAs($admin)->postJson('/api/files/blacklist/batch', [
        'file_ids' => [$file1->id, $file2->id],
    ]);

    $response->assertSuccessful()
        ->assertJsonStructure([
            'message',
            'results' => [
                '*' => ['id', 'blacklisted_at'],
            ],
        ])
        ->assertJsonMissingPath('results.0.blacklist_reason');

    $file1->refresh();
    $file2->refresh();
    $tab->refresh();

    expect($file1->blacklisted_at)->not->toBeNull()
        ->and($file2->blacklisted_at)->not->toBeNull()
        ->and($tab->files()->count())->toBe(0);
});

test('batch blacklist skips files that are already blacklisted', function () {
    $admin = User::factory()->admin()->create();
    $alreadyBlacklisted = File::factory()->create([
        'blacklisted_at' => now()->subHour(),
    ]);

    $response = $this->actingAs($admin)->postJson('/api/files/blacklist/batch', [
        'file_ids' => [$alreadyBlacklisted->id],
    ]);

    $response->assertSuccessful();
    $response->assertJsonCount(0, 'results');

    expect($alreadyBlacklisted->fresh()->blacklisted_at)->not->toBeNull();
});

test('batch blacklist clears auto-disliked marker and removes existing reactions because blacklist is plain blacklist', function () {
    $admin = User::factory()->admin()->create();
    $otherUser = User::factory()->create();
    $file = File::factory()->create([
        'auto_disliked' => true,
        'blacklisted_at' => null,
    ]);
    Reaction::create([
        'file_id' => $file->id,
        'user_id' => $admin->id,
        'type' => 'dislike',
    ]);
    Reaction::create([
        'file_id' => $file->id,
        'user_id' => $otherUser->id,
        'type' => 'love',
    ]);

    $response = $this->actingAs($admin)->postJson('/api/files/blacklist/batch', [
        'file_ids' => [$file->id],
    ]);

    $response->assertSuccessful();

    $file->refresh();

    expect($file->blacklisted_at)->not->toBeNull()
        ->and($file->auto_disliked)->toBeFalse()
        ->and(Reaction::where('file_id', $file->id)->count())->toBe(0);
});
