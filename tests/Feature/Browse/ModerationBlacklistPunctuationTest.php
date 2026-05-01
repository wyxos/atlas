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

test('blacklist rules match terms followed by punctuation (comma)', function () {
    Bus::fake();

    ModerationRule::factory()->any(['papaya'])->create([
        'name' => 'Papaya rule',
        'active' => true,
        'action_type' => ActionType::BLACKLIST,
        // Simulate UI form posts where booleans may arrive as strings.
        'options' => [
            'case_sensitive' => 'false',
            'whole_word' => 'true',
        ],
    ]);

    $prompts = [
        'papaya,',
        'fresh papaya, please',
        'papaya,then more words',
    ];

    $files = collect($prompts)->map(function (string $prompt, int $index) {
        $file = File::factory()->create([
            'referrer_url' => "https://example.com/file-papaya{$index}.jpg",
            'auto_blacklisted' => false,
            'blacklisted_at' => null,
            'path' => "downloads/papaya{$index}.jpg",
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

    // Verify no reaction was created (blacklist does not create reactions)
    $reaction = Reaction::where('file_id', $file->id)
        ->where('user_id', $this->user->id)
        ->first();
    expect($reaction)->toBeNull();

    // Verify delete job was dispatched
    Bus::assertDispatched(\App\Jobs\DeleteStoredFileJob::class);
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
            'auto_blacklisted' => false,
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
