<?php

use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function previewTestFile(array $attributes = []): File
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

test('increments preview count without moderating on first unreacted preview', function () {
    $admin = User::factory()->admin()->create();
    $file = previewTestFile(['previewed_count' => 0]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/preview");

    $response->assertSuccessful()
        ->assertJson([
            'previewed_count' => 1,
            'reaction' => null,
            'auto_blacklisted' => false,
            'blacklisted_at' => null,
        ]);

    $file->refresh();
    expect($file->previewed_count)->toBe(1)
        ->and($file->auto_blacklisted)->toBeFalse()
        ->and($file->blacklisted_at)->toBeNull()
        ->and(Reaction::where('file_id', $file->id)->count())->toBe(0);
});

test('auto blacklists unreacted item on second preview', function () {
    $admin = User::factory()->admin()->create();
    $file = previewTestFile(['previewed_count' => 1]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/preview");

    $response->assertSuccessful()
        ->assertJson([
            'previewed_count' => 2,
            'reaction' => null,
            'auto_blacklisted' => true,
        ]);

    $file->refresh();
    expect($file->previewed_count)->toBe(2)
        ->and($file->auto_blacklisted)->toBeTrue()
        ->and($file->blacklisted_at)->not->toBeNull()
        ->and(Reaction::where('file_id', $file->id)->count())->toBe(0);
});

test('does not auto blacklist local unreacted item on second preview', function () {
    $admin = User::factory()->admin()->create();
    $file = previewTestFile([
        'previewed_count' => 1,
        'source' => 'Local',
    ]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/preview");

    $response->assertSuccessful()
        ->assertJson([
            'previewed_count' => 2,
            'reaction' => null,
            'auto_blacklisted' => false,
            'blacklisted_at' => null,
        ]);

    $file->refresh();
    expect($file->previewed_count)->toBe(2)
        ->and($file->auto_blacklisted)->toBeFalse()
        ->and($file->blacklisted_at)->toBeNull();
});

test('preserves positive reaction on next preview', function () {
    $admin = User::factory()->admin()->create();
    $file = previewTestFile(['previewed_count' => 1]);
    Reaction::create([
        'file_id' => $file->id,
        'user_id' => $admin->id,
        'type' => 'like',
    ]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/preview");

    $response->assertSuccessful()
        ->assertJson([
            'previewed_count' => 2,
            'reaction' => ['type' => 'like'],
            'auto_blacklisted' => false,
            'blacklisted_at' => null,
        ]);

    $file->refresh();
    expect($file->previewed_count)->toBe(2)
        ->and($file->blacklisted_at)->toBeNull()
        ->and($file->auto_blacklisted)->toBeFalse()
        ->and(Reaction::where('file_id', $file->id)->value('type'))->toBe('like');
});

test('previewing auto blacklisted item moves it beyond blacklist review threshold', function () {
    $admin = User::factory()->admin()->create();
    $file = previewTestFile([
        'previewed_count' => 0,
        'auto_blacklisted' => true,
        'blacklisted_at' => now()->subMinute(),
    ]);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/preview");

    $response->assertSuccessful()
        ->assertJson([
            'previewed_count' => 99999,
            'reaction' => null,
            'auto_blacklisted' => true,
        ]);

    $file->refresh();
    expect($file->previewed_count)->toBe(99999)
        ->and($file->blacklisted_at)->not->toBeNull()
        ->and($file->auto_blacklisted)->toBeTrue()
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
            'previewed_count' => 99999,
            'reaction' => null,
            'auto_blacklisted' => false,
        ]);

    $file->refresh();
    expect($file->previewed_count)->toBe(99999)
        ->and($file->blacklisted_at)->not->toBeNull();
});
