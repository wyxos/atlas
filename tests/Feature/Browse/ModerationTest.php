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

test('files with matching prompts are flagged for auto-dislike', function () {
    // Create an active moderation rule
    $rule = ModerationRule::factory()->any(['spam', 'advertisement'])->create([
        'name' => 'Block spam',
        'active' => true,
    ]);

    // Create files with prompts
    $file1 = File::factory()->create([
        'referrer_url' => 'https://example.com/file1.jpg',
        'auto_disliked' => false,
        'path' => 'test/path1.jpg',
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file1->id,
        'payload' => ['prompt' => 'This is a spam advertisement'],
    ]);
    $file1->load('metadata');

    $file2 = File::factory()->create([
        'referrer_url' => 'https://example.com/file2.jpg',
        'auto_disliked' => false,
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file2->id,
        'payload' => ['prompt' => 'Beautiful landscape photo'],
    ]);
    $file2->load('metadata');

    // Call moderate directly
    $result = $this->service->moderate(collect([$file1, $file2]));

    // Assert file1 is flagged (but NOT auto-disliked in DB - that happens later via UI)
    expect($result['flaggedIds'])->toContain($file1->id)
        ->and($result['flaggedIds'])->not->toContain($file2->id);

    // Assert file is NOT auto-disliked in database (new behavior)
    expect($file1->fresh()->auto_disliked)->toBeFalse();
});

test('files without matching prompts are not flagged', function () {
    // Create an active moderation rule
    ModerationRule::factory()->any(['spam', 'advertisement'])->create([
        'active' => true,
    ]);

    // Create file with non-matching prompt
    $file = File::factory()->create([
        'referrer_url' => 'https://example.com/file.jpg',
        'auto_disliked' => false,
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

    // Assert file is not auto-disliked
    expect($file->fresh()->auto_disliked)->toBeFalse();
});

test('inactive rules are ignored', function () {
    // Create an inactive moderation rule
    ModerationRule::factory()->any(['spam', 'advertisement'])->create([
        'active' => false,
    ]);

    // Create file with matching prompt
    $file = File::factory()->create([
        'referrer_url' => 'https://example.com/file.jpg',
        'auto_disliked' => false,
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
    // Create multiple active rules
    $rule1 = ModerationRule::factory()->any(['spam'])->create([
        'name' => 'Spam rule',
        'active' => true,
    ]);
    $rule2 = ModerationRule::factory()->any(['advertisement'])->create([
        'name' => 'Ad rule',
        'active' => true,
    ]);

    // Create file matching first rule
    $file1 = File::factory()->create([
        'referrer_url' => 'https://example.com/file1.jpg',
        'auto_disliked' => false,
        'path' => 'test/path1.jpg',
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file1->id,
        'payload' => ['prompt' => 'This is spam content'],
    ]);
    $file1->load('metadata');

    // Create file matching second rule
    $file2 = File::factory()->create([
        'referrer_url' => 'https://example.com/file2.jpg',
        'auto_disliked' => false,
        'path' => 'test/path2.jpg',
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file2->id,
        'payload' => ['prompt' => 'This is an advertisement'],
    ]);
    $file2->load('metadata');

    // Call moderate directly
    $result = $this->service->moderate(collect([$file1, $file2]));

    // Assert both files are flagged
    expect($result['flaggedIds'])->toContain($file1->id)
        ->and($result['flaggedIds'])->toContain($file2->id);
});

test('files already auto-disliked are skipped', function () {
    // Create an active moderation rule
    ModerationRule::factory()->any(['spam'])->create([
        'name' => 'Spam rule',
        'active' => true,
    ]);

    // Create file already auto-disliked
    $file = File::factory()->create([
        'referrer_url' => 'https://example.com/file.jpg',
        'auto_disliked' => true,
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
        'auto_disliked' => false,
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file1->id,
        'payload' => [], // No prompt
    ]);
    $file1->load('metadata');

    // Create file without metadata
    $file2 = File::factory()->create([
        'referrer_url' => 'https://example.com/file2.jpg',
        'auto_disliked' => false,
    ]);

    // Call moderate directly
    $result = $this->service->moderate(collect([$file1, $file2]));

    // Assert files are not flagged
    expect($result['flaggedIds'])->toBeEmpty();
});

test('moderation result includes correct structure', function () {
    // Create an active moderation rule
    $rule = ModerationRule::factory()->any(['spam'])->create([
        'name' => 'Spam rule',
        'active' => true,
    ]);

    // Create file with matching prompt
    $file = File::factory()->create([
        'referrer_url' => 'https://example.com/file.jpg',
        'auto_disliked' => false,
        'thumbnail_url' => 'https://example.com/thumb.jpg',
        'filename' => 'test.jpg',
        'path' => 'test/path.jpg',
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file->id,
        'payload' => ['prompt' => 'This is spam content'],
    ]);
    $file->load('metadata');

    // Call moderate directly
    $result = $this->service->moderate(collect([$file]));

    // Assert result structure
    expect($result)->toHaveKeys(['flaggedIds', 'processedIds'])
        ->and($result['flaggedIds'])->toContain($file->id);
});

test('batch flagging works correctly for multiple files', function () {
    // Create an active moderation rule
    ModerationRule::factory()->any(['spam'])->create([
        'active' => true,
    ]);

    // Create multiple files with matching prompts
    $files = collect(range(1, 5))->map(function ($i) {
        $file = File::factory()->create([
            'referrer_url' => "https://example.com/file{$i}.jpg",
            'auto_disliked' => false,
            'path' => "test/path{$i}.jpg",
        ]);
        FileMetadata::factory()->create([
            'file_id' => $file->id,
            'payload' => ['prompt' => 'This is spam content'],
        ]);
        $file->load('metadata');

        return $file;
    });

    // Call moderate directly
    $result = $this->service->moderate($files);

    // Assert all files are flagged
    expect(count($result['flaggedIds']))->toBe(5);
    foreach ($files as $file) {
        expect($result['flaggedIds'])->toContain($file->id);
    }

    // Assert none are auto-disliked in DB (new behavior)
    foreach ($files as $file) {
        expect($file->fresh()->auto_disliked)->toBeFalse();
    }
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
        'auto_disliked' => false,
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
        'auto_disliked' => false,
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
    Bus::assertDispatched(\App\Jobs\DeleteAutoDislikedFileJob::class);
});

test('immediate blacklist updates file but does not create reaction', function () {
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
        'auto_disliked' => false,
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

    // Verify NO dislike reaction was created (blacklist does not create reactions)
    $reaction = Reaction::where('file_id', $file->id)
        ->where('user_id', $this->user->id)
        ->where('type', 'dislike')
        ->first();
    expect($reaction)->toBeNull();

    // Verify delete job was dispatched
    Bus::assertDispatched(\App\Jobs\DeleteAutoDislikedFileJob::class);
});

test('batch processing uses single query for multiple files', function () {
    Bus::fake();

    // Create an active moderation rule with blacklist action
    $rule = ModerationRule::factory()->any(['spam'])->create([
        'name' => 'Spam rule',
        'active' => true,
        'action_type' => ActionType::BLACKLIST,
    ]);

    // Create multiple files with matching prompts
    $files = collect(range(1, 3))->map(function ($i) {
        $file = File::factory()->create([
            'referrer_url' => "https://example.com/file{$i}.jpg",
            'auto_disliked' => false,
            'blacklisted_at' => null,
        ]);
        FileMetadata::factory()->create([
            'file_id' => $file->id,
            'payload' => ['prompt' => 'This is spam content'],
        ]);
        $file->load('metadata');

        return $file;
    });

    // Call moderate directly
    $result = $this->service->moderate($files);

    // Assert all files are processed
    expect(count($result['processedIds']))->toBe(3);

    // Assert all files are blacklisted (batch update)
    foreach ($files as $file) {
        expect($file->fresh()->blacklisted_at)->not->toBeNull();
    }
});
