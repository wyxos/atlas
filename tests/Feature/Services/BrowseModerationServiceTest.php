<?php

use App\Enums\ActionType;
use App\Jobs\DeleteStoredFileJob;
use App\Models\Container;
use App\Models\File;
use App\Models\FileMetadata;
use App\Models\ModerationRule;
use App\Models\Reaction;
use App\Models\Tab;
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

test('merges auto blacklist actions from file and container moderation', function () {
    ModerationRule::factory()->any(['spam'])->create([
        'active' => true,
        'action_type' => ActionType::BLACKLIST,
    ]);

    // Create files
    $file1 = File::factory()->create(['auto_blacklisted' => false]);
    FileMetadata::factory()->create([
        'file_id' => $file1->id,
        'payload' => ['prompt' => 'This is spam content'],
    ]);
    $file1->load('metadata');

    $file2 = File::factory()->create(['auto_blacklisted' => false]);
    FileMetadata::factory()->create([
        'file_id' => $file2->id,
        'payload' => ['prompt' => 'Beautiful landscape'],
    ]);
    $file2->load('metadata');

    $container = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => ActionType::BLACKLIST,
    ]);
    $file2->containers()->attach($container->id);
    $file2->load('containers');

    $result = $this->service->process([$file1, $file2]);

    expect($result['flaggedIds'])->toBeEmpty()
        ->and(collect($result['immediateActions'])->pluck('id')->all())->toContain($file1->id, $file2->id)
        ->and(collect($result['immediateActions'])->pluck('action_type')->all())->toContain('blacklist')
        ->and($file1->fresh()->blacklisted_at)->not->toBeNull()
        ->and($file2->fresh()->blacklisted_at)->not->toBeNull()
        ->and($file1->fresh()->auto_blacklisted)->toBeTrue()
        ->and($file2->fresh()->auto_blacklisted)->toBeTrue();
});

test('filters out blacklisted files from returned files', function () {
    Bus::fake();

    // Create blacklist moderation rule
    ModerationRule::factory()->any(['spam'])->create([
        'active' => true,
        'action_type' => ActionType::BLACKLIST,
    ]);

    $file1 = File::factory()->create([
        'auto_blacklisted' => false,
        'blacklisted_at' => null,
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file1->id,
        'payload' => ['prompt' => 'This is spam content'],
    ]);
    $file1->load('metadata');

    $file2 = File::factory()->create([
        'auto_blacklisted' => false,
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

test('blacklisting from moderation detaches the auth user tabs and clears stored assets', function () {
    Bus::fake();

    ModerationRule::factory()->any(['spam'])->create([
        'active' => true,
        'action_type' => ActionType::BLACKLIST,
    ]);

    $file = File::factory()->create([
        'auto_blacklisted' => false,
        'blacklisted_at' => null,
        'path' => 'downloads/moderated.jpg',
        'preview_path' => 'thumbnails/moderated.jpg',
        'poster_path' => 'posters/moderated.jpg',
        'downloaded' => true,
        'downloaded_at' => now(),
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file->id,
        'payload' => ['prompt' => 'This is spam content'],
    ]);
    $file->load('metadata');

    $currentTab = Tab::factory()->for($this->user)->create();
    $otherUserTab = Tab::factory()->for($this->user)->create();
    $otherUser = User::factory()->create();
    $foreignTab = Tab::factory()->for($otherUser)->create();

    $currentTab->files()->attach($file->id, ['position' => 0]);
    $otherUserTab->files()->attach($file->id, ['position' => 0]);
    $foreignTab->files()->attach($file->id, ['position' => 0]);

    $result = $this->service->process([$file]);

    expect($result['files'])->not->toContain($file)
        ->and($file->fresh()->blacklisted_at)->not->toBeNull()
        ->and($file->fresh()->path)->toBeNull()
        ->and($file->fresh()->preview_path)->toBeNull()
        ->and($file->fresh()->poster_path)->toBeNull()
        ->and($file->fresh()->downloaded)->toBeFalse()
        ->and($currentTab->fresh()->files()->where('file_id', $file->id)->exists())->toBeFalse()
        ->and($otherUserTab->fresh()->files()->where('file_id', $file->id)->exists())->toBeFalse()
        ->and($foreignTab->fresh()->files()->where('file_id', $file->id)->exists())->toBeTrue();

    Bus::assertDispatched(DeleteStoredFileJob::class, function (DeleteStoredFileJob $job) {
        if (! is_array($job->filePath)) {
            return false;
        }

        $paths = $job->filePath;
        sort($paths);

        return $paths === [
            'downloads/moderated.jpg',
            'posters/moderated.jpg',
            'thumbnails/moderated.jpg',
        ];
    });
});

test('keeps reacted files from blacklisted containers in returned files', function () {
    Bus::fake();

    $reactionUser = User::factory()->create();
    $container = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => ActionType::BLACKLIST,
    ]);

    $reactedFile = File::factory()->create([
        'auto_blacklisted' => false,
        'blacklisted_at' => null,
        'path' => 'downloads/reacted-container-browse.jpg',
    ]);
    $reactedFile->containers()->attach($container->id);
    Reaction::create([
        'file_id' => $reactedFile->id,
        'user_id' => $reactionUser->id,
        'type' => 'like',
    ]);
    $reactedFile->load('containers');

    $neutralFile = File::factory()->create([
        'auto_blacklisted' => false,
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
        'auto_blacklisted' => false,
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
        'auto_blacklisted' => false,
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

test('filters already auto-blacklisted files', function () {
    $file = File::factory()->create([
        'auto_blacklisted' => true,
        'blacklisted_at' => now(),
    ]);

    $result = $this->service->process([$file]);

    expect($result['files'])->not->toContain($file)
        ->and($result['immediateActions'])->toBeEmpty();
});

test('merges immediate actions from file and container moderation', function () {
    Bus::fake();

    // Create blacklist moderation rule
    ModerationRule::factory()->any(['spam'])->create([
        'active' => true,
        'action_type' => ActionType::BLACKLIST,
    ]);

    $file1 = File::factory()->create([
        'auto_blacklisted' => false,
        'blacklisted_at' => null,
        'preview_url' => 'https://example.com/thumb1.jpg',
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file1->id,
        'payload' => ['prompt' => 'This is spam content'],
    ]);
    $file1->load('metadata');

    $file2 = File::factory()->create([
        'auto_blacklisted' => false,
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
