<?php

use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use App\Services\FilePreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
});

test('removes auto_blacklisted flag when user reacts with like', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create(['auto_blacklisted' => true]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/reaction", [
        'type' => 'like',
    ]);

    $response->assertSuccessful();
    $file->refresh();
    expect($file->auto_blacklisted)->toBeFalse();

    $reaction = Reaction::where('file_id', $file->id)
        ->where('user_id', $admin->id)
        ->first();
    expect($reaction)->not->toBeNull();
    expect($reaction->type)->toBe('like');
});

test('removes auto_blacklisted flag when user reacts with funny', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create(['auto_blacklisted' => true]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/reaction", [
        'type' => 'funny',
    ]);

    $response->assertSuccessful();
    $file->refresh();
    expect($file->auto_blacklisted)->toBeFalse();
});

test('removes auto_blacklisted flag when user reacts with favorite', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create(['auto_blacklisted' => true]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/reaction", [
        'type' => 'love',
    ]);

    $response->assertSuccessful();
    $file->refresh();
    expect($file->auto_blacklisted)->toBeFalse();
});

test('rejects removed dislike reaction type', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create(['auto_blacklisted' => true]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/reaction", [
        'type' => 'dislike',
    ]);

    $response->assertUnprocessable();
    $file->refresh();
    expect($file->auto_blacklisted)->toBeTrue();
});

test('removes blacklist flags when user reacts with like on blacklisted file', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create([
        'blacklisted_at' => now(),
        'previewed_count' => FilePreviewService::FEED_REMOVED_PREVIEW_COUNT,
    ]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/reaction", [
        'type' => 'like',
    ]);

    $response->assertSuccessful();
    $file->refresh();
    expect($file->blacklisted_at)->toBeNull()
        ->and($file->previewed_count)->toBe(FilePreviewService::RECOVERED_PREVIEW_COUNT);
});

test('normalizes terminal preview count when reapplying an existing positive reaction', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create([
        'blacklisted_at' => null,
        'previewed_count' => FilePreviewService::FEED_REMOVED_PREVIEW_COUNT,
    ]);
    Reaction::create([
        'file_id' => $file->id,
        'user_id' => $admin->id,
        'type' => 'like',
    ]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/reaction", [
        'type' => 'like',
    ]);

    $response->assertSuccessful();
    $file->refresh();
    expect($file->blacklisted_at)->toBeNull()
        ->and($file->previewed_count)->toBe(FilePreviewService::RECOVERED_PREVIEW_COUNT)
        ->and($file->reactions()->where('user_id', $admin->id)->value('type'))->toBe('like');
});

test('removes blacklist flags when user reacts with love on blacklisted file', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create([
        'blacklisted_at' => now(),
    ]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/reaction", [
        'type' => 'love',
    ]);

    $response->assertSuccessful();
    $file->refresh();
    expect($file->blacklisted_at)->toBeNull();
});

test('removes blacklist flags when user reacts with funny on blacklisted file', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create([
        'blacklisted_at' => now(),
    ]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/reaction", [
        'type' => 'funny',
    ]);

    $response->assertSuccessful();
    $file->refresh();
    expect($file->blacklisted_at)->toBeNull();
});
