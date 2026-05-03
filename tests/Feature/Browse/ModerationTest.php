<?php

use App\Enums\ActionType;
use App\Enums\BlacklistPreviewedCountMode;
use App\Models\File;
use App\Models\FileMetadata;
use App\Models\ModerationRule;
use App\Models\Reaction;
use App\Models\User;
use App\Services\FileModerationService;
use App\Services\FilePreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->service = app(FileModerationService::class);
});

test('files with matching prompts are immediately Auto blacklisted', function () {
    $rule = ModerationRule::factory()->any(['spam', 'advertisement'])->create([
        'name' => 'Block spam',
        'active' => true,
        'action_type' => ActionType::BLACKLIST,
    ]);

    // Create files with prompts
    $file1 = File::factory()->create([
        'referrer_url' => 'https://example.com/file1.jpg',
        'auto_blacklisted' => false,
        'path' => 'test/path1.jpg',
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file1->id,
        'payload' => ['prompt' => 'This is a spam advertisement'],
    ]);
    $file1 = $file1->fresh()->load('metadata');

    $file2 = File::factory()->create([
        'referrer_url' => 'https://example.com/file2.jpg',
        'auto_blacklisted' => false,
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file2->id,
        'payload' => ['prompt' => 'Beautiful landscape photo'],
    ]);
    $file2 = $file2->fresh()->load('metadata');

    // Call moderate directly
    $result = $this->service->moderate(collect([$file1, $file2]));

    expect($result['flaggedIds'])->toBeEmpty()
        ->and($result['processedIds'])->toContain($file1->id)
        ->and($result['processedIds'])->not->toContain($file2->id);

    expect($file1->fresh()->auto_blacklisted)->toBeTrue();
    expect($file1->fresh()->blacklisted_at)->not->toBeNull();
    expect(Reaction::where('file_id', $file1->id)->exists())->toBeFalse();
});

test('persist the moderation rule that flagged a file for auto-blacklist', function () {
    $rule = ModerationRule::factory()->any(['spam', 'advertisement'])->create([
        'name' => 'Block spam',
        'active' => true,
        'action_type' => ActionType::BLACKLIST,
    ]);

    $file = File::factory()->create([
        'referrer_url' => 'https://example.com/file-rule-hit.jpg',
        'auto_blacklisted' => false,
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file->id,
        'payload' => ['prompt' => 'This is a spam advertisement'],
    ]);
    $file = $file->fresh()->load('metadata');

    $result = $this->service->moderate(collect([$file]));
    expect($result['flaggedIds'])->toBeEmpty();
    expect($result['processedIds'])->toContain($file->id);

    $this->assertDatabaseHas('file_moderation_actions', [
        'file_id' => $file->id,
        'action_type' => ActionType::BLACKLIST,
        'moderation_rule_id' => $rule->id,
        'moderation_rule_name' => $rule->name,
    ]);

    $response = $this->actingAs($this->user)->getJson("/api/files/{$file->id}");
    $response
        ->assertOk()
        ->assertJsonPath('file.auto_blacklisted', true)
        ->assertJsonPath('file.auto_blacklist_rule.id', $rule->id)
        ->assertJsonPath('file.auto_blacklist_rule.name', $rule->name);
});

test('rule blacklist preserves previewed count by default', function () {
    ModerationRule::factory()->any(['maybe'])->create([
        'name' => 'Reviewable rule',
        'active' => true,
        'action_type' => ActionType::BLACKLIST,
    ]);

    $file = File::factory()->create([
        'auto_blacklisted' => false,
        'blacklisted_at' => null,
        'path' => null,
        'preview_path' => null,
        'poster_path' => null,
        'downloaded' => false,
        'previewed_count' => 3,
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file->id,
        'payload' => ['prompt' => 'This includes maybe questionable content'],
    ]);

    $result = $this->service->moderate(collect([$file->fresh()->load('metadata')]));

    expect($result['processedIds'])->toContain($file->id);
    expect($file->fresh()->previewed_count)->toBe(3);
});

test('rule blacklist can set previewed count to feed removed count', function () {
    ModerationRule::factory()->any(['never'])->create([
        'name' => 'Permanent rule',
        'active' => true,
        'action_type' => ActionType::BLACKLIST,
        'blacklist_previewed_count_mode' => BlacklistPreviewedCountMode::FEED_REMOVED,
    ]);

    $file = File::factory()->create([
        'auto_blacklisted' => false,
        'blacklisted_at' => null,
        'path' => null,
        'preview_path' => null,
        'poster_path' => null,
        'downloaded' => false,
        'previewed_count' => 3,
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file->id,
        'payload' => ['prompt' => 'This includes never allowed content'],
    ]);

    $result = $this->service->moderate(collect([$file->fresh()->load('metadata')]));

    expect($result['processedIds'])->toContain($file->id);
    expect($file->fresh()->previewed_count)->toBe(FilePreviewService::FEED_REMOVED_PREVIEW_COUNT);
});

test('feed removed rule wins over earlier preserve rule', function () {
    ModerationRule::factory()->any(['shared'])->create([
        'name' => 'Reviewable first rule',
        'active' => true,
        'action_type' => ActionType::BLACKLIST,
        'blacklist_previewed_count_mode' => BlacklistPreviewedCountMode::PRESERVE,
    ]);
    $permanentRule = ModerationRule::factory()->any(['shared'])->create([
        'name' => 'Permanent second rule',
        'active' => true,
        'action_type' => ActionType::BLACKLIST,
        'blacklist_previewed_count_mode' => BlacklistPreviewedCountMode::FEED_REMOVED,
    ]);

    $file = File::factory()->create([
        'auto_blacklisted' => false,
        'blacklisted_at' => null,
        'path' => null,
        'preview_path' => null,
        'poster_path' => null,
        'downloaded' => false,
        'previewed_count' => 3,
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file->id,
        'payload' => ['prompt' => 'shared term'],
    ]);

    $this->service->moderate(collect([$file->fresh()->load('metadata')]));

    expect($file->fresh()->previewed_count)->toBe(FilePreviewService::FEED_REMOVED_PREVIEW_COUNT);
    $this->assertDatabaseHas('file_moderation_actions', [
        'file_id' => $file->id,
        'moderation_rule_id' => $permanentRule->id,
    ]);
});

test('files without matching prompts are not flagged', function () {
    // Create an active moderation rule
    ModerationRule::factory()->any(['spam', 'advertisement'])->create([
        'active' => true,
    ]);

    // Create file with non-matching prompt
    $file = File::factory()->create([
        'referrer_url' => 'https://example.com/file.jpg',
        'auto_blacklisted' => false,
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file->id,
        'payload' => ['prompt' => 'Beautiful landscape photo'],
    ]);
    $file->load('metadata');

    // Call moderate directly
    $result = $this->service->moderate(collect([$file]));

    // Assert file is not flagged
    expect($result['flaggedIds'])->toBeEmpty();

    // Assert file is not auto-blacklisted
    expect($file->fresh()->auto_blacklisted)->toBeFalse();
});

test('inactive rules are ignored', function () {
    // Create an inactive moderation rule
    ModerationRule::factory()->any(['spam', 'advertisement'])->create([
        'active' => false,
    ]);

    // Create file with matching prompt
    $file = File::factory()->create([
        'referrer_url' => 'https://example.com/file.jpg',
        'auto_blacklisted' => false,
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file->id,
        'payload' => ['prompt' => 'This is a spam advertisement'],
    ]);
    $file->load('metadata');

    // Call moderate directly
    $result = $this->service->moderate(collect([$file]));

    // Assert file is not flagged
    expect($result['flaggedIds'])->toBeEmpty();
});

test('multiple active rules are checked', function () {
    $rule1 = ModerationRule::factory()->any(['spam'])->create([
        'name' => 'Spam rule',
        'active' => true,
        'action_type' => ActionType::BLACKLIST,
    ]);
    $rule2 = ModerationRule::factory()->any(['advertisement'])->create([
        'name' => 'Ad rule',
        'active' => true,
        'action_type' => ActionType::BLACKLIST,
    ]);

    // Create file matching first rule
    $file1 = File::factory()->create([
        'referrer_url' => 'https://example.com/file1.jpg',
        'auto_blacklisted' => false,
        'path' => 'test/path1.jpg',
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file1->id,
        'payload' => ['prompt' => 'This is spam content'],
    ]);
    $file1 = $file1->fresh()->load('metadata');

    // Create file matching second rule
    $file2 = File::factory()->create([
        'referrer_url' => 'https://example.com/file2.jpg',
        'auto_blacklisted' => false,
        'path' => 'test/path2.jpg',
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file2->id,
        'payload' => ['prompt' => 'This is an advertisement'],
    ]);
    $file2 = $file2->fresh()->load('metadata');

    // Call moderate directly
    $result = $this->service->moderate(collect([$file1, $file2]));

    expect($result['flaggedIds'])->toBeEmpty()
        ->and($result['processedIds'])->toContain($file1->id)
        ->and($result['processedIds'])->toContain($file2->id);
    expect($file1->fresh()->auto_blacklisted)->toBeTrue();
    expect($file2->fresh()->auto_blacklisted)->toBeTrue();
});

test('files already blacklisted are skipped', function () {
    // Create an active moderation rule
    ModerationRule::factory()->any(['spam'])->create([
        'name' => 'Spam rule',
        'active' => true,
    ]);

    // Create file already auto-blacklisted
    $file = File::factory()->create([
        'referrer_url' => 'https://example.com/file.jpg',
        'auto_blacklisted' => true,
        'blacklisted_at' => now(),
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file->id,
        'payload' => ['prompt' => 'This is spam content'],
    ]);
    $file->load('metadata');

    // Call moderate directly
    $result = $this->service->moderate(collect([$file]));

    // Assert file is not flagged (already processed)
    expect($result['flaggedIds'])->toBeEmpty();
});

test('files without prompts are skipped', function () {
    // Create an active moderation rule
    ModerationRule::factory()->any(['spam'])->create([
        'active' => true,
    ]);

    // Create file without prompt
    $file1 = File::factory()->create([
        'referrer_url' => 'https://example.com/file1.jpg',
        'auto_blacklisted' => false,
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file1->id,
        'payload' => [], // No prompt
    ]);
    $file1->load('metadata');

    // Create file without metadata
    $file2 = File::factory()->create([
        'referrer_url' => 'https://example.com/file2.jpg',
        'auto_blacklisted' => false,
    ]);

    // Call moderate directly
    $result = $this->service->moderate(collect([$file1, $file2]));

    // Assert files are not flagged
    expect($result['flaggedIds'])->toBeEmpty();
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

test('empty file collection returns empty results', function () {
    ModerationRule::factory()->any(['spam'])->create([
        'active' => true,
    ]);

    $result = $this->service->moderate(collect([]));

    expect($result['flaggedIds'])->toBeEmpty()
        ->and($result['processedIds'])->toBeEmpty();
});

test('no active rules returns empty results', function () {
    $file = File::factory()->create([
        'referrer_url' => 'https://example.com/file.jpg',
        'auto_blacklisted' => false,
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file->id,
        'payload' => ['prompt' => 'This is spam content'],
    ]);
    $file->load('metadata');

    $result = $this->service->moderate(collect([$file]));

    expect($result['flaggedIds'])->toBeEmpty();
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

test('blacklist rules match buzz in underscores, quotes, and different casing', function () {
    Bus::fake();

    ModerationRule::factory()->any(['buzz'])->create([
        'name' => 'Buzz rule',
        'active' => true,
        'action_type' => ActionType::BLACKLIST,
        // Simulate UI form posts where booleans may arrive as strings.
        'options' => [
            'case_sensitive' => 'false',
            'whole_word' => 'true',
        ],
    ]);

    $prompts = [
        'need_buzz',
        'give_buzz_please',
        'BUZZ',
        '"Buzz Tips?"',
    ];

    $files = collect($prompts)->map(function (string $prompt, int $index) {
        $file = File::factory()->create([
            'referrer_url' => "https://example.com/file{$index}.jpg",
            'auto_blacklisted' => false,
            'blacklisted_at' => null,
            'path' => "downloads/test{$index}.jpg",
        ]);

        FileMetadata::factory()->create([
            'file_id' => $file->id,
            'payload' => ['prompt' => $prompt],
        ]);

        return $file->fresh()->load('metadata');
    });

    $result = $this->service->moderate($files);

    expect($result['flaggedIds'])->toBeEmpty();

    foreach ($files as $file) {
        expect($result['processedIds'])->toContain($file->id);
        expect($file->fresh()->blacklisted_at)->not->toBeNull();
    }
});

test('blacklist rules use detail_metadata prompt when metadata payload is missing', function () {
    Bus::fake();

    ModerationRule::factory()->any(['buzz'])->create([
        'name' => 'Buzz rule',
        'active' => true,
        'action_type' => ActionType::BLACKLIST,
        'options' => [
            'case_sensitive' => 'false',
            'whole_word' => 'true',
        ],
    ]);

    $file = File::factory()->create([
        'auto_blacklisted' => false,
        'blacklisted_at' => null,
        'path' => 'downloads/buzz-detail.jpg',
        'detail_metadata' => [
            'prompt' => 'Master Yoda holding a glowing yellow lightning bolt symbol, above it glowing green text reads "Buzz you have? Send, you should.", futuristic sci-fi background',
        ],
    ]);

    FileMetadata::factory()->create([
        'file_id' => $file->id,
        'payload' => [],
    ]);

    $file = $file->fresh()->load('metadata');

    $result = $this->service->moderate(collect([$file]));

    expect($result['flaggedIds'])->toBeEmpty();
    expect($result['processedIds'])->toContain($file->id);
    expect($file->fresh()->blacklisted_at)->not->toBeNull();
});
