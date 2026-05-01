<?php

use App\Jobs\DeleteStoredFileJob;
use App\Models\Container;
use App\Models\File;
use App\Models\ModerationRule;
use App\Models\Reaction;
use App\Models\User;
use App\Services\Local\LocalBrowseIndexSyncService;
use App\Services\MetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

function mockBlacklistOnlyCutoverIndexSync(): void
{
    $sync = mock(LocalBrowseIndexSyncService::class);
    $sync->shouldReceive('syncFilesByIds')->zeroOrMoreTimes()->andReturnNull();
    $sync->shouldReceive('syncReactionsForFileIds')->zeroOrMoreTimes()->andReturnNull();
}

test('dry run reports legacy negative state without mutating rows', function () {
    $user = User::factory()->create();
    $autoFile = File::factory()->create([
        'auto_blacklisted' => true,
        'blacklisted_at' => null,
    ]);
    $manualFile = File::factory()->create([
        'auto_blacklisted' => false,
        'blacklisted_at' => null,
    ]);
    Reaction::query()->create([
        'file_id' => $manualFile->id,
        'user_id' => $user->id,
        'type' => 'dislike',
    ]);
    $rule = ModerationRule::factory()->create([
        'action_type' => 'dislike',
    ]);

    $this->artisan('atlas:cutover-blacklist-only --dry-run --skip-ddl')
        ->assertExitCode(0);

    expect($autoFile->fresh()->blacklisted_at)->toBeNull()
        ->and($manualFile->fresh()->blacklisted_at)->toBeNull()
        ->and(Reaction::query()->where('file_id', $manualFile->id)->where('type', 'dislike')->exists())->toBeTrue()
        ->and($rule->fresh()->action_type)->toBe('dislike');
});

test('converts auto-blacklisted files and legacy dislike reactions to blacklist-only state in chunks', function () {
    Queue::fake();
    mockBlacklistOnlyCutoverIndexSync();

    $user = User::factory()->create();
    $autoFile = File::factory()->create([
        'auto_blacklisted' => true,
        'blacklisted_at' => null,
        'path' => 'downloads/auto.jpg',
        'preview_path' => null,
        'poster_path' => null,
        'downloaded' => true,
        'downloaded_at' => now(),
    ]);
    $manualFile = File::factory()->create([
        'auto_blacklisted' => false,
        'blacklisted_at' => null,
        'path' => 'downloads/manual.jpg',
        'preview_path' => null,
        'poster_path' => null,
        'downloaded' => true,
        'downloaded_at' => now(),
    ]);
    $plainBlacklist = File::factory()->create([
        'auto_blacklisted' => false,
        'blacklisted_at' => now(),
    ]);

    Reaction::query()->create([
        'file_id' => $autoFile->id,
        'user_id' => $user->id,
        'type' => 'love',
    ]);
    Reaction::query()->create([
        'file_id' => $manualFile->id,
        'user_id' => $user->id,
        'type' => 'dislike',
    ]);
    Reaction::query()->create([
        'file_id' => $manualFile->id,
        'user_id' => User::factory()->create()->id,
        'type' => 'like',
    ]);

    $rule = ModerationRule::factory()->create([
        'action_type' => 'dislike',
    ]);
    $container = Container::factory()->create([
        'action_type' => 'dislike',
    ]);
    DB::table('file_moderation_actions')->insert([
        'file_id' => $autoFile->id,
        'action_type' => 'dislike',
        'moderation_rule_id' => $rule->id,
        'moderation_rule_name' => $rule->name,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('file_moderation_actions')->insert([
        'file_id' => $autoFile->id,
        'action_type' => 'blacklist',
        'moderation_rule_id' => $rule->id,
        'moderation_rule_name' => $rule->name,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('atlas:cutover-blacklist-only --chunk=1 --skip-ddl')
        ->assertExitCode(0);

    expect($autoFile->fresh()->blacklisted_at)->not->toBeNull()
        ->and($autoFile->fresh()->auto_blacklisted)->toBeTrue()
        ->and($autoFile->fresh()->path)->toBeNull()
        ->and($autoFile->fresh()->downloaded)->toBeFalse()
        ->and($manualFile->fresh()->blacklisted_at)->not->toBeNull()
        ->and($manualFile->fresh()->auto_blacklisted)->toBeFalse()
        ->and($manualFile->fresh()->path)->toBeNull()
        ->and($manualFile->fresh()->downloaded)->toBeFalse()
        ->and($plainBlacklist->fresh()->blacklisted_at)->not->toBeNull()
        ->and($plainBlacklist->fresh()->auto_blacklisted)->toBeFalse();

    expect(Reaction::query()->whereIn('file_id', [$autoFile->id, $manualFile->id])->exists())->toBeFalse();
    expect($rule->fresh()->action_type)->toBe('blacklist')
        ->and($container->fresh()->action_type)->toBe('blacklist')
        ->and(DB::table('file_moderation_actions')->where('file_id', $autoFile->id)->value('action_type'))->toBe('blacklist');
    expect(DB::table('file_moderation_actions')->where('file_id', $autoFile->id)->where('action_type', 'blacklist')->count())->toBe(1)
        ->and(DB::table('file_moderation_actions')->where('file_id', $autoFile->id)->where('action_type', 'dislike')->exists())->toBeFalse();

    $metrics = app(MetricsService::class)->getMetrics([
        MetricsService::KEY_FILES_BLACKLISTED_TOTAL,
        MetricsService::KEY_FILES_AUTO_BLACKLISTED,
        MetricsService::KEY_REACTIONS_LOVE,
        MetricsService::KEY_REACTIONS_LIKE,
    ]);
    expect($metrics[MetricsService::KEY_FILES_BLACKLISTED_TOTAL])->toBe(3)
        ->and($metrics[MetricsService::KEY_FILES_AUTO_BLACKLISTED])->toBe(1)
        ->and($metrics[MetricsService::KEY_REACTIONS_LOVE])->toBe(0)
        ->and($metrics[MetricsService::KEY_REACTIONS_LIKE])->toBe(0);

    Queue::assertPushed(DeleteStoredFileJob::class, 2);
});
