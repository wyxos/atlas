<?php

use App\Enums\ActionType;
use App\Models\File;
use App\Models\FileMetadata;
use App\Models\ModerationRule;
use App\Models\Reaction;
use App\Models\User;
use App\Services\FileModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->service = app(FileModerationService::class);
});

test('moderation result includes correct structure', function () {
    $rule = ModerationRule::factory()->any(['spam'])->create([
        'name' => 'Spam rule',
        'active' => true,
        'action_type' => ActionType::BLACKLIST,
    ]);

    // Create file with matching prompt
    $file = File::factory()->create([
        'referrer_url' => 'https://example.com/file.jpg',
        'auto_blacklisted' => false,
        'preview_url' => 'https://example.com/thumb.jpg',
        'filename' => 'test.jpg',
        'path' => 'test/path.jpg',
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file->id,
        'payload' => ['prompt' => 'This is spam content'],
    ]);
    $file = $file->fresh()->load('metadata');

    // Call moderate directly
    $result = $this->service->moderate(collect([$file]));

    // Assert result structure
    expect($result)->toHaveKeys(['flaggedIds', 'processedIds'])
        ->and($result['flaggedIds'])->toBeEmpty()
        ->and($result['processedIds'])->toContain($file->id);
});

test('batch flagging works correctly for multiple files', function () {
    ModerationRule::factory()->any(['spam'])->create([
        'active' => true,
        'action_type' => ActionType::BLACKLIST,
    ]);

    // Create multiple files with matching prompts
    $files = collect(range(1, 5))->map(function ($i) {
        $file = File::factory()->create([
            'referrer_url' => "https://example.com/file{$i}.jpg",
            'auto_blacklisted' => false,
            'path' => "test/path{$i}.jpg",
        ]);
        FileMetadata::factory()->create([
            'file_id' => $file->id,
            'payload' => ['prompt' => 'This is spam content'],
        ]);

        return $file->fresh()->load('metadata');
    });

    // Call moderate directly
    $result = $this->service->moderate($files);

    expect($result['flaggedIds'])->toBeEmpty();
    expect(count($result['processedIds']))->toBe(5);
    foreach ($files as $file) {
        expect($result['processedIds'])->toContain($file->id);
    }

    foreach ($files as $file) {
        expect($file->fresh()->auto_blacklisted)->toBeTrue();
    }
});

test('auto blacklist skips files that already have any reaction', function () {
    ModerationRule::factory()->any(['spam'])->create([
        'active' => true,
        'action_type' => ActionType::BLACKLIST,
    ]);

    $currentUserReactedFile = File::factory()->create([
        'referrer_url' => 'https://example.com/current-user-reacted.jpg',
        'auto_blacklisted' => false,
    ]);
    FileMetadata::factory()->create([
        'file_id' => $currentUserReactedFile->id,
        'payload' => ['prompt' => 'This is spam content'],
    ]);
    Reaction::create([
        'file_id' => $currentUserReactedFile->id,
        'user_id' => $this->user->id,
        'type' => 'like',
    ]);

    $otherUser = User::factory()->create();
    $otherUserReactedFile = File::factory()->create([
        'referrer_url' => 'https://example.com/other-user-reacted.jpg',
        'auto_blacklisted' => false,
    ]);
    FileMetadata::factory()->create([
        'file_id' => $otherUserReactedFile->id,
        'payload' => ['prompt' => 'This is spam content'],
    ]);
    Reaction::create([
        'file_id' => $otherUserReactedFile->id,
        'user_id' => $otherUser->id,
        'type' => 'love',
    ]);

    $result = $this->service->moderate(collect([
        $currentUserReactedFile->fresh()->load('metadata'),
        $otherUserReactedFile->fresh()->load('metadata'),
    ]));

    expect($result['flaggedIds'])->toBeEmpty()
        ->and($result['processedIds'])->not->toContain($currentUserReactedFile->id)
        ->and($result['processedIds'])->not->toContain($otherUserReactedFile->id)
        ->and($currentUserReactedFile->fresh()->auto_blacklisted)->toBeFalse()
        ->and($otherUserReactedFile->fresh()->auto_blacklisted)->toBeFalse();
});

test('immediate blacklist updates file', function () {
    Bus::fake();

    // Create an active moderation rule with blacklist action
    $rule = ModerationRule::factory()->any(['spam'])->create([
        'name' => 'Spam rule',
        'active' => true,
        'action_type' => ActionType::BLACKLIST,
    ]);

    // Create file with matching prompt
    $file = File::factory()->create([
        'referrer_url' => 'https://example.com/file.jpg',
        'auto_blacklisted' => false,
        'blacklisted_at' => null,
        'path' => 'downloads/test.jpg',
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file->id,
        'payload' => ['prompt' => 'This is spam content'],
    ]);
    $file->load('metadata');

    // Call moderate directly
    $result = $this->service->moderate(collect([$file]));

    // Assert file is processed (not flagged for UI)
    expect($result['flaggedIds'])->toBeEmpty()
        ->and($result['processedIds'])->toContain($file->id);

    // Assert file is blacklisted in database
    expect($file->fresh()->blacklisted_at)->not->toBeNull();

    // Verify delete job was dispatched
    Bus::assertDispatched(\App\Jobs\DeleteStoredFileJob::class);
});

test('blacklist skips files that already have any reaction', function () {
    Bus::fake();

    ModerationRule::factory()->any(['spam'])->create([
        'name' => 'Spam rule',
        'active' => true,
        'action_type' => ActionType::BLACKLIST,
    ]);

    $reactionUser = User::factory()->create();
    $file = File::factory()->create([
        'referrer_url' => 'https://example.com/reacted-blacklist-skip.jpg',
        'auto_blacklisted' => false,
        'blacklisted_at' => null,
        'path' => 'downloads/reacted-blacklist-skip.jpg',
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file->id,
        'payload' => ['prompt' => 'This is spam content'],
    ]);
    Reaction::create([
        'file_id' => $file->id,
        'user_id' => $reactionUser->id,
        'type' => 'like',
    ]);

    $result = $this->service->moderate(collect([$file->fresh()->load('metadata')]));

    expect($result['flaggedIds'])->toBeEmpty()
        ->and($result['processedIds'])->toBeEmpty()
        ->and($file->fresh()->blacklisted_at)->toBeNull();

    $this->assertDatabaseMissing('file_moderation_actions', [
        'file_id' => $file->id,
        'action_type' => ActionType::BLACKLIST,
    ]);

    Bus::assertNothingDispatched();
});

test('persist the moderation rule that blacklisted a file without classification', function () {
    Bus::fake();

    $rule = ModerationRule::factory()->any(['spam'])->create([
        'name' => 'Spam rule',
        'active' => true,
        'action_type' => ActionType::BLACKLIST,
    ]);

    $file = File::factory()->create([
        'referrer_url' => 'https://example.com/file-blacklist-hit.jpg',
        'auto_blacklisted' => false,
        'blacklisted_at' => null,
        'path' => 'downloads/test-blacklist-hit.jpg',
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file->id,
        'payload' => ['prompt' => 'This is spam content'],
    ]);
    $file = $file->fresh()->load('metadata');

    $result = $this->service->moderate(collect([$file]));
    expect($result['flaggedIds'])->toBeEmpty()
        ->and($result['processedIds'])->toContain($file->id);

    $this->assertDatabaseHas('file_moderation_actions', [
        'file_id' => $file->id,
        'action_type' => ActionType::BLACKLIST,
        'moderation_rule_id' => $rule->id,
        'moderation_rule_name' => $rule->name,
    ]);

    $response = $this->actingAs($this->user)->getJson("/api/files/{$file->id}");
    $response
        ->assertOk()
        ->assertJsonMissingPath('file.blacklist_type')
        ->assertJsonMissingPath('file.blacklist_reason')
        ->assertJsonPath('file.blacklist_rule.id', $rule->id)
        ->assertJsonPath('file.blacklist_rule.name', $rule->name);
});
