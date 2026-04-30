<?php

use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function previewTestFile(array $attributes = []): File
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

test('increments preview count without moderating on first unreacted preview', function () {
    $admin = User::factory()->admin()->create();
    $file = previewTestFile(['previewed_count' => 0]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/preview");

    $response->assertSuccessful()
        ->assertJson([
            'previewed_count' => 1,
            'reaction' => null,
            'auto_disliked' => false,
            'blacklisted_at' => null,
        ]);

    $file->refresh();
    expect($file->previewed_count)->toBe(1)
        ->and($file->auto_disliked)->toBeFalse()
        ->and($file->blacklisted_at)->toBeNull()
        ->and(Reaction::where('file_id', $file->id)->count())->toBe(0);
});

test('auto dislikes unreacted item on second preview', function () {
    $admin = User::factory()->admin()->create();
    $file = previewTestFile(['previewed_count' => 1]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/preview");

    $response->assertSuccessful()
        ->assertJson([
            'previewed_count' => 2,
            'reaction' => ['type' => 'dislike'],
            'auto_disliked' => true,
            'blacklisted_at' => null,
        ]);

    $file->refresh();
    expect($file->previewed_count)->toBe(2)
        ->and($file->auto_disliked)->toBeTrue()
        ->and($file->blacklisted_at)->toBeNull()
        ->and(Reaction::where('file_id', $file->id)->where('user_id', $admin->id)->value('type'))->toBe('dislike');
});

test('blacklists manual disliked item on third preview', function () {
    $admin = User::factory()->admin()->create();
    $file = previewTestFile(['previewed_count' => 2]);
    Reaction::create([
        'file_id' => $file->id,
        'user_id' => $admin->id,
        'type' => 'dislike',
    ]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/preview");

    $response->assertSuccessful()
        ->assertJson([
            'previewed_count' => 4,
            'reaction' => null,
            'auto_disliked' => false,
        ]);

    $file->refresh();
    expect($file->previewed_count)->toBe(4)
        ->and($file->blacklisted_at)->not->toBeNull()
        ->and($file->auto_disliked)->toBeFalse()
        ->and(Reaction::where('file_id', $file->id)->count())->toBe(0)
        ->and($response->json('blacklisted_at'))->not->toBeNull();
});

test('blacklists auto disliked item on next preview', function () {
    $admin = User::factory()->admin()->create();
    $file = previewTestFile([
        'previewed_count' => 0,
        'auto_disliked' => true,
    ]);
    Reaction::create([
        'file_id' => $file->id,
        'user_id' => $admin->id,
        'type' => 'dislike',
    ]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/preview");

    $response->assertSuccessful()
        ->assertJson([
            'previewed_count' => 4,
            'reaction' => null,
            'auto_disliked' => false,
        ]);

    $file->refresh();
    expect($file->previewed_count)->toBe(4)
        ->and($file->blacklisted_at)->not->toBeNull()
        ->and($file->auto_disliked)->toBeFalse()
        ->and(Reaction::where('file_id', $file->id)->count())->toBe(0);
});

test('previewing blacklisted item moves it beyond blacklist review threshold', function () {
    $admin = User::factory()->admin()->create();
    $file = previewTestFile([
        'previewed_count' => 1,
        'blacklisted_at' => now()->subMinute(),
    ]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/preview");

    $response->assertSuccessful()
        ->assertJson([
            'previewed_count' => 4,
            'reaction' => null,
            'auto_disliked' => false,
        ]);

    $file->refresh();
    expect($file->previewed_count)->toBe(4)
        ->and($file->blacklisted_at)->not->toBeNull();
});
