<?php

use App\Models\File;
use App\Models\Tab;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('batch blacklists loaded files and detaches them from the current users tabs', function () {
    $admin = User::factory()->admin()->create();
    $file1 = File::factory()->create([
        'blacklisted_at' => null,
        'blacklist_reason' => null,
    ]);
    $file2 = File::factory()->create([
        'blacklisted_at' => null,
        'blacklist_reason' => null,
    ]);
    $tab = Tab::factory()
        ->for($admin)
        ->withFiles([$file1->id, $file2->id])
        ->create();

    $response = $this->actingAs($admin)->postJson('/api/files/blacklist/batch', [
        'file_ids' => [$file1->id, $file2->id],
    ]);

    $response->assertSuccessful();
    $response->assertJsonStructure([
        'message',
        'results' => [
            '*' => ['id', 'blacklisted_at', 'blacklist_reason'],
        ],
    ]);

    $file1->refresh();
    $file2->refresh();
    $tab->refresh();

    expect($file1->blacklisted_at)->not->toBeNull()
        ->and($file2->blacklisted_at)->not->toBeNull()
        ->and($file1->blacklist_reason)->toBe('Manual blacklist')
        ->and($file2->blacklist_reason)->toBe('Manual blacklist')
        ->and($tab->files()->count())->toBe(0);
});

test('batch blacklist skips files that are already blacklisted', function () {
    $admin = User::factory()->admin()->create();
    $alreadyBlacklisted = File::factory()->create([
        'blacklisted_at' => now()->subHour(),
        'blacklist_reason' => 'Manual blacklist',
    ]);

    $response = $this->actingAs($admin)->postJson('/api/files/blacklist/batch', [
        'file_ids' => [$alreadyBlacklisted->id],
    ]);

    $response->assertSuccessful();
    $response->assertJsonCount(0, 'results');

    $alreadyBlacklisted->refresh();

    expect($alreadyBlacklisted->blacklist_reason)->toBe('Manual blacklist');
});
