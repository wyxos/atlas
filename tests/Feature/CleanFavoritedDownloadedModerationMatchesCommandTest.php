<?php

use App\Enums\ActionType;
use App\Enums\BlacklistPreviewedCountMode;
use App\Jobs\DeleteStoredFileJob;
use App\Models\Container;
use App\Models\File;
use App\Models\FileMetadata;
use App\Models\ModerationRule;
use App\Models\Reaction;
use App\Models\User;
use App\Services\FilePreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

test('dry run reports aggregate matches without mutating rows or surfacing content', function () {
    Bus::fake();

    $rule = ModerationRule::factory()->any(['private-rule-term'])->create([
        'name' => 'Private rule name',
        'active' => true,
        'action_type' => ActionType::BLACKLIST,
    ]);
    $file = cleanupFavoritedDownloadedFile([
        'filename' => 'sensitive-file.jpg',
        'downloaded_at' => '2026-05-25 08:15:00',
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file->id,
        'payload' => ['prompt' => 'private-rule-term in a sensitive prompt'],
    ]);

    Artisan::call('atlas:clean-favorited-downloaded-moderation-matches', [
        '--date' => '2026-05-25',
        '--dry-run' => true,
        '--chunk' => 1,
    ]);

    $output = Artisan::output();

    expect($file->fresh()->blacklisted_at)->toBeNull()
        ->and(Reaction::query()->where('file_id', $file->id)->exists())->toBeTrue()
        ->and($output)->toContain('candidate files: 1')
        ->and($output)->toContain('Matched files: 1')
        ->and($output)->toContain('Prompt-rule matches: 1')
        ->and($output)->toContain('Reactions that would be removed: 1')
        ->and($output)->toContain('Files that would be blacklisted: 1')
        ->and($output)->toContain('Dry run only. No rows were changed.')
        ->and($output)->not->toContain($rule->name)
        ->and($output)->not->toContain('private-rule-term')
        ->and($output)->not->toContain('sensitive prompt')
        ->and($output)->not->toContain($file->filename);

    $this->assertDatabaseMissing('file_moderation_actions', [
        'file_id' => $file->id,
        'action_type' => ActionType::BLACKLIST,
    ]);
    Bus::assertNothingDispatched();
});

test('applies highest prompt rule level to favorited files downloaded on the target day', function () {
    Bus::fake();

    ModerationRule::factory()->any(['cleanup-target'])->create([
        'name' => 'Preserve rule',
        'active' => true,
        'action_type' => ActionType::BLACKLIST,
        'blacklist_previewed_count_mode' => BlacklistPreviewedCountMode::PRESERVE,
    ]);
    $feedRemovedRule = ModerationRule::factory()->any(['cleanup-target'])->create([
        'name' => 'Feed removed rule',
        'active' => true,
        'action_type' => ActionType::BLACKLIST,
        'blacklist_previewed_count_mode' => BlacklistPreviewedCountMode::FEED_REMOVED,
    ]);

    $target = cleanupFavoritedDownloadedFile([
        'downloaded_at' => '2026-05-25 12:30:00',
        'previewed_count' => 2,
    ]);
    FileMetadata::factory()->create([
        'file_id' => $target->id,
        'payload' => ['prompt' => 'cleanup-target should be removed'],
    ]);

    $likedOnly = cleanupFavoritedDownloadedFile([
        'downloaded_at' => '2026-05-25 13:00:00',
    ], 'like');
    FileMetadata::factory()->create([
        'file_id' => $likedOnly->id,
        'payload' => ['prompt' => 'cleanup-target should not be targeted'],
    ]);

    $wrongDate = cleanupFavoritedDownloadedFile([
        'downloaded_at' => '2026-05-24 23:59:00',
    ]);
    FileMetadata::factory()->create([
        'file_id' => $wrongDate->id,
        'payload' => ['prompt' => 'cleanup-target should not be targeted'],
    ]);

    $noMatch = cleanupFavoritedDownloadedFile([
        'downloaded_at' => '2026-05-25 14:00:00',
    ]);
    FileMetadata::factory()->create([
        'file_id' => $noMatch->id,
        'payload' => ['prompt' => 'safe prompt'],
    ]);

    Artisan::call('atlas:clean-favorited-downloaded-moderation-matches', [
        '--date' => '2026-05-25',
        '--force' => true,
        '--chunk' => 1,
    ]);

    $output = Artisan::output();
    $freshTarget = $target->fresh();

    expect($freshTarget->blacklisted_at)->not->toBeNull()
        ->and($freshTarget->auto_blacklisted)->toBeTrue()
        ->and($freshTarget->previewed_count)->toBe(FilePreviewService::FEED_REMOVED_PREVIEW_COUNT)
        ->and($freshTarget->downloaded)->toBeFalse()
        ->and($freshTarget->downloaded_at)->toBeNull()
        ->and(Reaction::query()->where('file_id', $target->id)->exists())->toBeFalse()
        ->and($likedOnly->fresh()->blacklisted_at)->toBeNull()
        ->and($wrongDate->fresh()->blacklisted_at)->toBeNull()
        ->and($noMatch->fresh()->blacklisted_at)->toBeNull()
        ->and($output)->toContain('candidate files: 2')
        ->and($output)->toContain('Matched files: 1')
        ->and($output)->toContain('Feed-removed level matches: 1')
        ->and($output)->toContain('Reactions removed: 1')
        ->and($output)->toContain('Files blacklisted: 1');

    $this->assertDatabaseHas('file_moderation_actions', [
        'file_id' => $target->id,
        'action_type' => ActionType::BLACKLIST,
        'moderation_rule_id' => $feedRemovedRule->id,
        'moderation_rule_name' => $feedRemovedRule->name,
    ]);
    Bus::assertDispatched(DeleteStoredFileJob::class);
});

test('applies blacklisted container level when no prompt rule matches', function () {
    Bus::fake();

    $file = cleanupFavoritedDownloadedFile([
        'downloaded_at' => '2026-05-25 16:00:00',
        'previewed_count' => 5,
    ]);
    $container = Container::factory()->create([
        'blacklisted_at' => now(),
        'blacklist_previewed_count_mode' => BlacklistPreviewedCountMode::FEED_REMOVED,
    ]);
    $container->files()->attach($file->id);

    Artisan::call('atlas:clean-favorited-downloaded-moderation-matches', [
        '--date' => '2026-05-25',
        '--force' => true,
    ]);

    $output = Artisan::output();
    $freshFile = $file->fresh();

    expect($freshFile->blacklisted_at)->not->toBeNull()
        ->and($freshFile->auto_blacklisted)->toBeTrue()
        ->and($freshFile->previewed_count)->toBe(FilePreviewService::FEED_REMOVED_PREVIEW_COUNT)
        ->and(Reaction::query()->where('file_id', $file->id)->exists())->toBeFalse()
        ->and($output)->toContain('Prompt-rule matches: 0')
        ->and($output)->toContain('Blacklisted-container matches: 1')
        ->and($output)->toContain('Feed-removed level matches: 1');

    $this->assertDatabaseMissing('file_moderation_actions', [
        'file_id' => $file->id,
        'action_type' => ActionType::BLACKLIST,
    ]);
});

function cleanupFavoritedDownloadedFile(array $attributes = [], string $reactionType = 'love'): File
{
    $user = User::factory()->create();
    $file = File::factory()->create(array_merge([
        'downloaded' => true,
        'downloaded_at' => '2026-05-25 12:00:00',
        'path' => 'downloads/cleanup/original.jpg',
        'preview_path' => 'downloads/cleanup/preview.jpg',
        'poster_path' => null,
        'download_progress' => 100,
        'blacklisted_at' => null,
        'auto_blacklisted' => false,
    ], $attributes));

    Reaction::query()->create([
        'file_id' => $file->id,
        'user_id' => $user->id,
        'type' => $reactionType,
    ]);

    return $file;
}
