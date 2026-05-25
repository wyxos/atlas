<?php

use App\Enums\BlacklistPreviewedCountMode;
use App\Enums\ModerationFeedRemovalRunStatus;
use App\Jobs\PreviewModerationFeedRemovalRun;
use App\Jobs\SyncLibraryFiles;
use App\Models\File;
use App\Models\FileMetadata;
use App\Models\ModerationFeedRemovalRun;
use App\Models\ModerationFeedRemovalRunFile;
use App\Models\ModerationRule;
use App\Models\User;
use App\Services\FilePreviewService;
use App\Services\MetricsService;
use App\Services\Moderation\FeedRemovalBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('preview endpoint queues an aggregate-only moderation feed removal report', function () {
    Queue::fake();

    $user = User::factory()->create();
    $rule = ModerationRule::factory()->any(['private-rule-term'])->create([
        'name' => 'Private rule name',
        'blacklist_previewed_count_mode' => BlacklistPreviewedCountMode::FEED_REMOVED,
    ]);
    $file = createFeedRemovalBackfillFile([
        'url' => 'https://private.example/sensitive-file.jpg',
        'filename' => 'sensitive-file.jpg',
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file->id,
        'payload' => ['prompt' => 'private-rule-term in a sensitive prompt'],
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/settings/moderation-feed-removal-runs/preview', [
            'chunk_size' => 250,
        ]);

    $response->assertAccepted()
        ->assertJsonPath('run.status', ModerationFeedRemovalRunStatus::PENDING)
        ->assertJsonPath('run.chunk_size', 250)
        ->assertJsonMissingPath('run.rules_hash');

    $body = json_encode($response->json(), JSON_THROW_ON_ERROR);

    expect($body)->not->toContain($rule->name)
        ->and($body)->not->toContain('private-rule-term')
        ->and($body)->not->toContain('sensitive prompt')
        ->and($body)->not->toContain($file->filename)
        ->and($body)->not->toContain((string) $file->url);

    Queue::assertPushed(PreviewModerationFeedRemovalRun::class);
});

test('backfill service previews exact matches and applies only that report', function () {
    Queue::fake();

    $matchingFile = createFeedRemovalBackfillFile(['previewed_count' => 2]);
    FileMetadata::factory()->create([
        'file_id' => $matchingFile->id,
        'payload' => ['prompt' => 'remove-from-feed marker'],
    ]);

    $otherMatchingFile = createFeedRemovalBackfillFile([
        'previewed_count' => 3,
        'listing_metadata' => ['meta' => ['prompt' => 'remove-from-feed from listing metadata']],
    ]);

    $noPromptFile = createFeedRemovalBackfillFile(['previewed_count' => 4]);
    $noMatchFile = createFeedRemovalBackfillFile(['previewed_count' => 5]);
    FileMetadata::factory()->create([
        'file_id' => $noMatchFile->id,
        'payload' => ['prompt' => 'unmatched prompt text'],
    ]);

    $alreadyFeedRemovedFile = createFeedRemovalBackfillFile([
        'previewed_count' => FilePreviewService::FEED_REMOVED_PREVIEW_COUNT,
    ]);
    FileMetadata::factory()->create([
        'file_id' => $alreadyFeedRemovedFile->id,
        'payload' => ['prompt' => 'remove-from-feed marker'],
    ]);

    ModerationRule::factory()->any(['remove-from-feed'])->create([
        'active' => true,
        'blacklist_previewed_count_mode' => BlacklistPreviewedCountMode::FEED_REMOVED,
    ]);

    app(MetricsService::class)->syncAll();

    $run = ModerationFeedRemovalRun::factory()->create([
        'chunk_size' => 1,
    ]);

    $service = app(FeedRemovalBackfillService::class);
    $preview = $service->previewRun($run);

    expect($preview->status)->toBe(ModerationFeedRemovalRunStatus::PREVIEWED)
        ->and($preview->scanned_count)->toBe(4)
        ->and($preview->skipped_no_prompt_count)->toBe(1)
        ->and($preview->matched_count)->toBe(2)
        ->and($preview->updated_count)->toBe(0)
        ->and(ModerationFeedRemovalRunFile::query()->where('moderation_feed_removal_run_id', $run->id)->count())->toBe(2)
        ->and($matchingFile->fresh()->previewed_count)->toBe(2);

    $applied = $service->applyRun($preview);

    expect($applied->status)->toBe(ModerationFeedRemovalRunStatus::APPLIED)
        ->and($applied->updated_count)->toBe(2)
        ->and($matchingFile->fresh()->previewed_count)->toBe(FilePreviewService::FEED_REMOVED_PREVIEW_COUNT)
        ->and($otherMatchingFile->fresh()->previewed_count)->toBe(FilePreviewService::FEED_REMOVED_PREVIEW_COUNT)
        ->and($noPromptFile->fresh()->previewed_count)->toBe(4)
        ->and($noMatchFile->fresh()->previewed_count)->toBe(5)
        ->and($alreadyFeedRemovedFile->fresh()->previewed_count)->toBe(FilePreviewService::FEED_REMOVED_PREVIEW_COUNT);

    Queue::assertPushed(SyncLibraryFiles::class, 2);
});

test('apply is blocked when active feed removal rules changed after preview', function () {
    Queue::fake();

    $file = createFeedRemovalBackfillFile(['previewed_count' => 2]);
    FileMetadata::factory()->create([
        'file_id' => $file->id,
        'payload' => ['prompt' => 'remove-from-feed marker'],
    ]);

    $rule = ModerationRule::factory()->any(['remove-from-feed'])->create([
        'active' => true,
        'blacklist_previewed_count_mode' => BlacklistPreviewedCountMode::FEED_REMOVED,
    ]);

    $run = ModerationFeedRemovalRun::factory()->create();
    $service = app(FeedRemovalBackfillService::class);
    $preview = $service->previewRun($run);

    $rule->update(['terms' => ['changed-rule-term']]);

    $applied = $service->applyRun($preview->fresh());

    expect($applied->status)->toBe(ModerationFeedRemovalRunStatus::STALE)
        ->and($file->fresh()->previewed_count)->toBe(2);

    Queue::assertNothingPushed();
});

function createFeedRemovalBackfillFile(array $attributes = []): File
{
    return File::factory()->create(array_merge([
        'auto_blacklisted' => false,
        'blacklisted_at' => now(),
        'previewed_count' => 1,
    ], $attributes));
}
