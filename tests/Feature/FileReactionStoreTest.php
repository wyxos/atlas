<?php

use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
});

test('removes auto_disliked flag when user reacts with like', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create(['auto_disliked' => true]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/reaction", [
        'type' => 'like',
    ]);

    $response->assertSuccessful();
    $file->refresh();
    expect($file->auto_disliked)->toBeFalse();

    $reaction = Reaction::where('file_id', $file->id)
        ->where('user_id', $admin->id)
        ->first();
    expect($reaction)->not->toBeNull();
    expect($reaction->type)->toBe('like');
});

test('removes auto_disliked flag when user reacts with funny', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create(['auto_disliked' => true]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/reaction", [
        'type' => 'funny',
    ]);

    $response->assertSuccessful();
    $file->refresh();
    expect($file->auto_disliked)->toBeFalse();
});

test('removes auto_disliked flag when user reacts with favorite', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create(['auto_disliked' => true]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/reaction", [
        'type' => 'love',
    ]);

    $response->assertSuccessful();
    $file->refresh();
    expect($file->auto_disliked)->toBeFalse();
});

test('keeps auto_disliked flag when user manually dislikes', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create(['auto_disliked' => true]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/reaction", [
        'type' => 'dislike',
    ]);

    $response->assertSuccessful();
    $file->refresh();
    expect($file->auto_disliked)->toBeTrue();
});

test('removes blacklist flags when user reacts with like on blacklisted file', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create([
        'blacklisted_at' => now(),
        'blacklist_reason' => 'Test reason',
    ]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/reaction", [
        'type' => 'like',
    ]);

    $response->assertSuccessful();
    $file->refresh();
    expect($file->blacklisted_at)->toBeNull();
    expect($file->blacklist_reason)->toBeNull();
});

test('removes blacklist flags when user reacts with love on blacklisted file', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create([
        'blacklisted_at' => now(),
        'blacklist_reason' => 'Test reason',
    ]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/reaction", [
        'type' => 'love',
    ]);

    $response->assertSuccessful();
    $file->refresh();
    expect($file->blacklisted_at)->toBeNull();
    expect($file->blacklist_reason)->toBeNull();
});

test('removes blacklist flags when user reacts with funny on blacklisted file', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create([
        'blacklisted_at' => now(),
        'blacklist_reason' => 'Test reason',
    ]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/reaction", [
        'type' => 'funny',
    ]);

    $response->assertSuccessful();
    $file->refresh();
    expect($file->blacklisted_at)->toBeNull();
    expect($file->blacklist_reason)->toBeNull();
});

test('keeps blacklist flags when user reacts with dislike on blacklisted file', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create([
        'blacklisted_at' => now(),
        'blacklist_reason' => 'Test reason',
    ]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/reaction", [
        'type' => 'dislike',
    ]);

    $response->assertSuccessful();
    $file->refresh();
    expect($file->blacklisted_at)->not->toBeNull();
    expect($file->blacklist_reason)->toBe('Test reason');
});
