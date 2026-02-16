<?php

use App\Models\File;
use App\Models\ModerationRule;
use App\Models\User;
use App\Services\FileModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('file show includes blacklist type and persisted rule for auto-blacklisted files', function () {
    $user = User::factory()->create();

    $file = File::factory()->create([
        'blacklisted_at' => null,
        'blacklist_reason' => null,
        'auto_disliked' => false,
        'path' => null,
    ]);

    $file->metadata()->create([
        'payload' => [
            'prompt' => 'this contains car somewhere',
        ],
    ]);

    $rule = ModerationRule::factory()->create([
        'name' => 'Car blacklist',
        'active' => true,
        'action_type' => 'blacklist',
        'op' => 'any',
        'terms' => ['car'],
    ]);

    app(FileModerationService::class)->moderate(collect([$file->fresh()->load('metadata')]));

    $response = $this->actingAs($user)->getJson("/api/files/{$file->id}");

    $response->assertSuccessful();
    $response->assertJsonPath('file.blacklist_type', 'auto');
    $response->assertJsonPath('file.blacklist_rule.id', $rule->id);
    $response->assertJsonPath('file.blacklist_rule.name', $rule->name);
});

test('file show includes manual blacklist type when reason is present', function () {
    $user = User::factory()->create();

    $file = File::factory()->create([
        'blacklisted_at' => now(),
        'blacklist_reason' => 'manual note',
    ]);

    $response = $this->actingAs($user)->getJson("/api/files/{$file->id}");

    $response->assertSuccessful();
    $response->assertJsonPath('file.blacklist_type', 'manual');
});
