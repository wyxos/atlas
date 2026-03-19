<?php

use App\Enums\ActionType;
use App\Models\Container;
use App\Models\File;
use App\Models\FileMetadata;
use App\Models\ModerationRule;
use App\Models\Reaction;
use App\Models\User;
use App\Services\BrowseModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->service = app(BrowseModerationService::class);
});

test('merges flagged IDs from file and container moderation', function () {
    // Create file moderation rule with DISLIKE action
    ModerationRule::factory()->any(['spam'])->create([
        'active' => true,
        'action_type' => ActionType::DISLIKE,
    ]);

    // Create files
    $file1 = File::factory()->create(['auto_disliked' => false]);
    FileMetadata::factory()->create([
        'file_id' => $file1->id,
        'payload' => ['prompt' => 'This is spam content'],
    ]);
    $file1->load('metadata');

    $file2 = File::factory()->create(['auto_disliked' => false]);
    FileMetadata::factory()->create([
        'file_id' => $file2->id,
        'payload' => ['prompt' => 'Beautiful landscape'],
    ]);
    $file2->load('metadata');

    // Create container with DISLIKE action type - this will flag file2
    $container = Container::factory()->create([
        'blacklisted_at' => now(), // Container is blacklisted
        'action_type' => ActionType::DISLIKE, // With dislike action (flags, doesn't blacklist)
    ]);
    $file2->containers()->attach($container->id);
    $file2->load('containers');

    $result = $this->service->process([$file1, $file2]);

    // Both files should be flagged (file1 from moderation rule, file2 from container)
    expect($result['flaggedIds'])->toContain($file1->id)
        ->and($result['flaggedIds'])->toContain($file2->id);
});

test('filters out blacklisted files from returned files', function () {
    Bus::fake();

    // Create blacklist moderation rule
    ModerationRule::factory()->any(['spam'])->create([
        'active' => true,
        'action_type' => ActionType::BLACKLIST,
    ]);

    $file1 = File::factory()->create([
        'auto_disliked' => false,
        'blacklisted_at' => null,
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file1->id,
        'payload' => ['prompt' => 'This is spam content'],
    ]);
    $file1->load('metadata');

    $file2 = File::factory()->create([
        'auto_disliked' => false,
        'blacklisted_at' => null,
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file2->id,
        'payload' => ['prompt' => 'Beautiful landscape'],
    ]);
    $file2->load('metadata');

    $result = $this->service->process([$file1, $file2]);

    // file1 should be blacklisted and filtered out
    expect($file1->fresh()->blacklisted_at)->not->toBeNull()
        ->and($result['files'])->not->toContain($file1)
        ->and($result['files'])->toContain($file2);
});

test('keeps reacted files from blacklisted containers in returned files', function () {
    Bus::fake();

    $reactionUser = User::factory()->create();
    $container = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => ActionType::BLACKLIST,
    ]);

    $reactedFile = File::factory()->create([
        'auto_disliked' => false,
        'blacklisted_at' => null,
        'path' => 'downloads/reacted-container-browse.jpg',
    ]);
    $reactedFile->containers()->attach($container->id);
    Reaction::create([
        'file_id' => $reactedFile->id,
        'user_id' => $reactionUser->id,
        'type' => 'dislike',
    ]);
    $reactedFile->load('containers');

    $neutralFile = File::factory()->create([
        'auto_disliked' => false,
        'blacklisted_at' => null,
        'path' => 'downloads/neutral-container-browse.jpg',
    ]);
    $neutralFile->containers()->attach($container->id);
    $neutralFile->load('containers');

    $result = $this->service->process([$reactedFile, $neutralFile]);

    expect(collect($result['files'])->pluck('id')->all())->toBe([$reactedFile->id]);
    expect($reactedFile->fresh()->blacklisted_at)->toBeNull();
    expect($neutralFile->fresh()->blacklisted_at)->not->toBeNull();
});

test('filters out already blacklisted files', function () {
    $file1 = File::factory()->create([
        'blacklisted_at' => now(),
    ]);

    $file2 = File::factory()->create([
        'blacklisted_at' => null,
    ]);

    $result = $this->service->process([$file1, $file2]);

    // file1 should be filtered out (already blacklisted)
    expect($result['files'])->not->toContain($file1)
        ->and($result['files'])->toContain($file2);
});

test('formats immediate actions correctly', function () {
    Bus::fake();

    // Create blacklist moderation rule
    ModerationRule::factory()->any(['spam'])->create([
        'active' => true,
        'action_type' => ActionType::BLACKLIST,
    ]);

    $file = File::factory()->create([
        'auto_disliked' => false,
        'blacklisted_at' => null,
        'preview_url' => 'https://example.com/thumb.jpg',
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file->id,
        'payload' => ['prompt' => 'This is spam content'],
    ]);
    $file->load('metadata');

    $result = $this->service->process([$file]);

    // Should have immediate action for blacklisted file
    expect($result['immediateActions'])->toBeArray()
        ->and(count($result['immediateActions']))->toBe(1)
        ->and($result['immediateActions'][0])->toHaveKeys(['id', 'action_type', 'thumbnail'])
        ->and($result['immediateActions'][0]['id'])->toBe($file->id)
        ->and($result['immediateActions'][0]['action_type'])->toBe('blacklist')
        ->and($result['immediateActions'][0]['thumbnail'])->toBe('https://example.com/thumb.jpg');
});

test('returns empty immediate actions when no files are processed', function () {
    $file = File::factory()->create([
        'auto_disliked' => false,
        'blacklisted_at' => null,
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file->id,
        'payload' => ['prompt' => 'Beautiful landscape'],
    ]);
    $file->load('metadata');

    $result = $this->service->process([$file]);

    // No immediate actions (file was not blacklisted)
    expect($result['immediateActions'])->toBeArray()
        ->and($result['immediateActions'])->toBeEmpty();
});

test('handles empty file collection', function () {
    $result = $this->service->process([]);

    expect($result['files'])->toBeArray()
        ->and($result['files'])->toBeEmpty()
        ->and($result['flaggedIds'])->toBeArray()
        ->and($result['flaggedIds'])->toBeEmpty()
        ->and($result['immediateActions'])->toBeArray()
        ->and($result['immediateActions'])->toBeEmpty();
});

test('does not filter auto-disliked files', function () {
    // Create dislike moderation rule
    ModerationRule::factory()->any(['spam'])->create([
        'active' => true,
        'action_type' => ActionType::DISLIKE,
    ]);

    $file = File::factory()->create([
        'auto_disliked' => true, // Already auto-disliked
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file->id,
        'payload' => ['prompt' => 'This is spam content'],
    ]);
    $file->load('metadata');

    $result = $this->service->process([$file]);

    // Auto-disliked files should still be returned (not filtered)
    expect($result['files'])->toContain($file);
});

test('merges immediate actions from file and container moderation', function () {
    Bus::fake();

    // Create blacklist moderation rule
    ModerationRule::factory()->any(['spam'])->create([
        'active' => true,
        'action_type' => ActionType::BLACKLIST,
    ]);

    $file1 = File::factory()->create([
        'auto_disliked' => false,
        'blacklisted_at' => null,
        'preview_url' => 'https://example.com/thumb1.jpg',
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file1->id,
        'payload' => ['prompt' => 'This is spam content'],
    ]);
    $file1->load('metadata');

    $file2 = File::factory()->create([
        'auto_disliked' => false,
        'blacklisted_at' => null,
        'preview_url' => 'https://example.com/thumb2.jpg',
    ]);

    // Create blacklisted container and attach to file2
    $container = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => ActionType::BLACKLIST,
    ]);
    $file2->containers()->attach($container->id);
    $file2->load('containers');

    $result = $this->service->process([$file1, $file2]);

    // Both files should have immediate actions
    expect(count($result['immediateActions']))->toBe(2)
        ->and(collect($result['immediateActions'])->pluck('id')->toArray())
        ->toContain($file1->id, $file2->id);
});
