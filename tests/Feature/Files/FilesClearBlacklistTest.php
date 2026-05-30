<?php

use App\Models\File;
use App\Models\User;
use App\Services\FilePreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('user can clear blacklist state from a file', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'blacklisted_at' => now(),
        'auto_blacklisted' => true,
        'previewed_count' => FilePreviewService::FEED_REMOVED_PREVIEW_COUNT,
    ]);

    $response = $this->actingAs($user)->deleteJson("/api/files/{$file->id}/blacklist");

    $response->assertSuccessful()
        ->assertJsonPath('file.id', $file->id)
        ->assertJsonPath('file.blacklisted_at', null)
        ->assertJsonPath('file.auto_blacklisted', false)
        ->assertJsonPath('file.previewed_count', FilePreviewService::RECOVERED_PREVIEW_COUNT);

    $file->refresh();

    expect($file->blacklisted_at)->toBeNull()
        ->and($file->auto_blacklisted)->toBeFalse()
        ->and($file->previewed_count)->toBe(FilePreviewService::RECOVERED_PREVIEW_COUNT);
});

test('clearing blacklist is idempotent for files without blacklist state', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'blacklisted_at' => null,
        'auto_blacklisted' => false,
        'previewed_count' => 4,
    ]);

    $response = $this->actingAs($user)->deleteJson("/api/files/{$file->id}/blacklist");

    $response->assertSuccessful()
        ->assertJsonPath('file.blacklisted_at', null)
        ->assertJsonPath('file.auto_blacklisted', false)
        ->assertJsonPath('file.previewed_count', 4);
});
