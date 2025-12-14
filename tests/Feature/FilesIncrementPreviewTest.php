<?php

use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('increments preview count', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create(['previewed_count' => 0]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/preview");

    $response->assertSuccessful();
    $response->assertJson([
        'previewed_count' => 1,
        'will_auto_dislike' => false,
    ]);

    $file->refresh();
    expect($file->previewed_count)->toBe(1);
});

test('returns will_auto_dislike flag when previewed_count reaches 3 and no reactions exist', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create([
        'previewed_count' => 2,
        'source' => 'wallhaven', // Not 'local'
        'path' => null, // No path (not on disk)
        'blacklisted_at' => null, // Not blacklisted
    ]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/preview");

    $response->assertSuccessful();
    $response->assertJson([
        'previewed_count' => 3,
        'will_auto_dislike' => true,
    ]);

    $file->refresh();
    expect($file->previewed_count)->toBe(3);
    // File is NOT auto-disliked immediately - UI handles countdown
    expect($file->auto_disliked)->toBeFalse();
});

test('does not return will_auto_dislike when file already has reactions', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create(['previewed_count' => 2]);
    Reaction::create([
        'file_id' => $file->id,
        'user_id' => $admin->id,
        'type' => 'like',
    ]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/preview");

    $response->assertSuccessful();
    $response->assertJson([
        'previewed_count' => 3,
        'will_auto_dislike' => false,
    ]);

    $file->refresh();
    expect($file->auto_disliked)->toBeFalse();
});

test('does not return will_auto_dislike when previewed_count is less than 3', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create(['previewed_count' => 1]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/preview");

    $response->assertSuccessful();
    $response->assertJson([
        'previewed_count' => 2,
        'will_auto_dislike' => false,
    ]);

    $file->refresh();
    expect($file->auto_disliked)->toBeFalse();
});

test('does not return will_auto_dislike when file source is local', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create([
        'previewed_count' => 2,
        'source' => 'local', // Local source
        'path' => null,
    ]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/preview");

    $response->assertSuccessful();
    $response->assertJson([
        'previewed_count' => 3,
        'will_auto_dislike' => false,
    ]);

    $file->refresh();
    expect($file->auto_disliked)->toBeFalse();
});

test('does not return will_auto_dislike when file has a path', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create([
        'previewed_count' => 2,
        'source' => 'wallhaven',
        'path' => 'private/images/ab/cd/test.jpg', // Has path (on disk)
    ]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/preview");

    $response->assertSuccessful();
    $response->assertJson([
        'previewed_count' => 3,
        'will_auto_dislike' => false,
    ]);

    $file->refresh();
    expect($file->auto_disliked)->toBeFalse();
});

test('does not return will_auto_dislike when file is blacklisted', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create([
        'previewed_count' => 2,
        'source' => 'wallhaven',
        'path' => null,
        'blacklisted_at' => now(), // File is blacklisted
    ]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/preview");

    $response->assertSuccessful();
    $response->assertJson([
        'previewed_count' => 3,
        'will_auto_dislike' => false,
    ]);

    $file->refresh();
    expect($file->auto_disliked)->toBeFalse();
});
