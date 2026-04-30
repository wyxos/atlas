<?php

use App\Models\File;
use App\Models\ModerationRule;
use App\Models\User;
use App\Services\FileModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('file show includes persisted rule for rule-blacklisted files without blacklist classification', function () {
    $user = User::factory()->create();

    $file = File::factory()->create([
        'blacklisted_at' => null,
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

    $response->assertSuccessful()
        ->assertJsonMissingPath('file.blacklist_type')
        ->assertJsonMissingPath('file.blacklist_reason');
    $response->assertJsonPath('file.blacklist_rule.id', $rule->id);
    $response->assertJsonPath('file.blacklist_rule.name', $rule->name);
});
