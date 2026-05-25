<?php

use App\Enums\BlacklistPreviewedCountMode;
use App\Jobs\SyncLibraryFiles;
use App\Models\File;
use App\Models\FileMetadata;
use App\Models\ModerationRule;
use App\Services\FilePreviewService;
use App\Services\MetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('dry run reports aggregate matches without mutating rows or surfacing content', function () {
    Queue::fake();

    $rule = ModerationRule::factory()->any(['private-rule-term'])->create([
        'name' => 'Private rule name',
        'blacklist_previewed_count_mode' => BlacklistPreviewedCountMode::FEED_REMOVED,
    ]);
    $file = File::factory()->create([
        'url' => 'https://private.example/sensitive-file.jpg',
        'filename' => 'sensitive-file.jpg',
        'blacklisted_at' => now(),
        'previewed_count' => 4,
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file->id,
        'payload' => ['prompt' => 'private-rule-term in a sensitive prompt'],
    ]);

    Artisan::call('atlas:mark-feed-removed-rule-matches', [
        '--dry-run' => true,
        '--chunk' => 1,
    ]);

    $output = Artisan::output();

    expect($file->fresh()->previewed_count)->toBe(4)
        ->and($output)->toContain('active feed-removal rules: 1')
        ->and($output)->toContain('Matched rows: 1')
        ->and($output)->not->toContain($rule->name)
        ->and($output)->not->toContain('private-rule-term')
        ->and($output)->not->toContain('sensitive prompt')
        ->and($output)->not->toContain($file->filename)
        ->and($output)->not->toContain((string) $file->url);

    Queue::assertNothingPushed();
});

test('marks only blacklisted files matching feed removal rules in chunks', function () {
    Queue::fake();

    $matchingMetadataFile = createBackfillBlacklistedFile(['previewed_count' => 2]);
    FileMetadata::factory()->create([
        'file_id' => $matchingMetadataFile->id,
        'payload' => ['prompt' => 'remove-from-feed marker'],
    ]);

    $matchingListingFile = createBackfillBlacklistedFile([
        'previewed_count' => 3,
        'listing_metadata' => ['meta' => ['prompt' => 'remove-from-feed from listing metadata']],
    ]);

    $preserveRuleOnlyFile = createBackfillBlacklistedFile(['previewed_count' => 4]);
    FileMetadata::factory()->create([
        'file_id' => $preserveRuleOnlyFile->id,
        'payload' => ['prompt' => 'preserve-rule-only marker'],
    ]);

    $inactiveRuleFile = createBackfillBlacklistedFile(['previewed_count' => 5]);
    FileMetadata::factory()->create([
        'file_id' => $inactiveRuleFile->id,
        'payload' => ['prompt' => 'inactive-feed-rule marker'],
    ]);

    $noPromptFile = createBackfillBlacklistedFile(['previewed_count' => 6]);
    $noMatchFile = createBackfillBlacklistedFile(['previewed_count' => 7]);
    FileMetadata::factory()->create([
        'file_id' => $noMatchFile->id,
        'payload' => ['prompt' => 'unmatched prompt text'],
    ]);

    $alreadyFeedRemovedFile = createBackfillBlacklistedFile([
        'previewed_count' => FilePreviewService::FEED_REMOVED_PREVIEW_COUNT,
    ]);
    FileMetadata::factory()->create([
        'file_id' => $alreadyFeedRemovedFile->id,
        'payload' => ['prompt' => 'remove-from-feed marker'],
    ]);

    $notBlacklistedFile = File::factory()->create([
        'blacklisted_at' => null,
        'previewed_count' => 1,
    ]);
    FileMetadata::factory()->create([
        'file_id' => $notBlacklistedFile->id,
        'payload' => ['prompt' => 'remove-from-feed marker'],
    ]);

    ModerationRule::factory()->any(['remove-from-feed'])->create([
        'active' => true,
        'blacklist_previewed_count_mode' => BlacklistPreviewedCountMode::FEED_REMOVED,
    ]);
    ModerationRule::factory()->any(['preserve-rule-only'])->create([
        'active' => true,
        'blacklist_previewed_count_mode' => BlacklistPreviewedCountMode::PRESERVE,
    ]);
    ModerationRule::factory()->any(['inactive-feed-rule'])->create([
        'active' => false,
        'blacklist_previewed_count_mode' => BlacklistPreviewedCountMode::FEED_REMOVED,
    ]);

    app(MetricsService::class)->syncAll();

    $this->artisan('atlas:mark-feed-removed-rule-matches --chunk=1')
        ->expectsOutputToContain('active feed-removal rules: 1')
        ->expectsOutputToContain('Scanned rows: 6')
        ->expectsOutputToContain('Skipped rows without prompt: 1')
        ->expectsOutputToContain('Matched rows: 2')
        ->expectsOutputToContain('Updated rows: 2')
        ->assertExitCode(0);

    expect($matchingMetadataFile->fresh()->previewed_count)->toBe(FilePreviewService::FEED_REMOVED_PREVIEW_COUNT)
        ->and($matchingListingFile->fresh()->previewed_count)->toBe(FilePreviewService::FEED_REMOVED_PREVIEW_COUNT)
        ->and($preserveRuleOnlyFile->fresh()->previewed_count)->toBe(4)
        ->and($inactiveRuleFile->fresh()->previewed_count)->toBe(5)
        ->and($noPromptFile->fresh()->previewed_count)->toBe(6)
        ->and($noMatchFile->fresh()->previewed_count)->toBe(7)
        ->and($alreadyFeedRemovedFile->fresh()->previewed_count)->toBe(FilePreviewService::FEED_REMOVED_PREVIEW_COUNT)
        ->and($notBlacklistedFile->fresh()->previewed_count)->toBe(1);

    $metrics = app(MetricsService::class)->getMetrics([
        MetricsService::KEY_FILES_BLACKLISTED_FEED_REMOVED,
        MetricsService::KEY_FILES_BLACKLISTED_MANUAL_IN_FEED,
    ]);

    expect($metrics[MetricsService::KEY_FILES_BLACKLISTED_FEED_REMOVED])->toBe(3)
        ->and($metrics[MetricsService::KEY_FILES_BLACKLISTED_MANUAL_IN_FEED])->toBe(4);

    Queue::assertPushed(SyncLibraryFiles::class, 2);
});

function createBackfillBlacklistedFile(array $attributes = []): File
{
    return File::factory()->create(array_merge([
        'auto_blacklisted' => false,
        'blacklisted_at' => now(),
        'previewed_count' => 1,
    ], $attributes));
}
