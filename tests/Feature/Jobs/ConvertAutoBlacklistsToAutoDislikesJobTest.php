<?php

use App\Jobs\ConvertAutoBlacklistsToAutoDislikes;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use App\Services\Local\LocalBrowseIndexSyncService;
use App\Services\MetricsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

beforeEach(function () {
    addLegacyBlacklistReasonColumnForJobTest();
});

function addLegacyBlacklistReasonColumnForJobTest(): void
{
    if (Schema::hasColumn('files', 'blacklist_reason')) {
        return;
    }

    Schema::table('files', function (Blueprint $table): void {
        $table->text('blacklist_reason')->nullable();
    });
}

function createLegacyBlacklistedFileForJobTest(?string $reason = null, array $attributes = []): File
{
    $file = File::factory()->create($attributes + [
        'blacklisted_at' => now(),
        'auto_disliked' => false,
        'path' => null,
        'preview_path' => null,
        'poster_path' => null,
        'downloaded' => false,
        'downloaded_at' => null,
    ]);

    DB::table('files')
        ->where('id', $file->id)
        ->update(['blacklist_reason' => $reason]);

    return $file->fresh();
}

function mockLegacyConversionIndexSync(?File $file = null): void
{
    $sync = mock(LocalBrowseIndexSyncService::class);

    if ($file instanceof File) {
        $sync->shouldReceive('syncFilesByIds')
            ->once()
            ->with([$file->id])
            ->andReturnNull();
        $sync->shouldReceive('syncReactionsForFileIds')
            ->once()
            ->with([$file->id])
            ->andReturnNull();

        return;
    }

    $sync->shouldReceive('syncFilesByIds')->zeroOrMoreTimes()->andReturnNull();
    $sync->shouldReceive('syncReactionsForFileIds')->zeroOrMoreTimes()->andReturnNull();
}

test('dry run scans candidates without mutating files or reactions', function () {
    $user = User::factory()->create();
    $file = createLegacyBlacklistedFileForJobTest();

    $job = new ConvertAutoBlacklistsToAutoDislikes($user->id, afterId: 0, chunk: 10, queueName: 'processing', dryRun: true);
    app()->call([$job, 'handle']);

    expect($file->fresh()->blacklisted_at)->not->toBeNull()
        ->and($file->fresh()->auto_disliked)->toBeFalse();
    expect(Reaction::query()->where('file_id', $file->id)->exists())->toBeFalse();
});

test('converts a legacy null reason blacklist into an auto dislike', function () {
    $user = User::factory()->create();
    $file = createLegacyBlacklistedFileForJobTest();
    mockLegacyConversionIndexSync($file);

    app(MetricsService::class)->syncAll();

    $job = new ConvertAutoBlacklistsToAutoDislikes($user->id, afterId: 0, chunk: 10, queueName: 'processing');
    app()->call([$job, 'handle']);

    $file->refresh();

    expect($file->blacklisted_at)->toBeNull()
        ->and($file->auto_disliked)->toBeTrue();
    $this->assertDatabaseHas('reactions', [
        'file_id' => $file->id,
        'user_id' => $user->id,
        'type' => 'dislike',
    ]);

    $metrics = app(MetricsService::class)->getMetrics([
        MetricsService::KEY_FILES_BLACKLISTED_TOTAL,
        MetricsService::KEY_FILES_AUTO_DISLIKED,
        MetricsService::KEY_REACTIONS_DISLIKE,
    ]);

    expect($metrics[MetricsService::KEY_FILES_BLACKLISTED_TOTAL])->toBe(0)
        ->and($metrics[MetricsService::KEY_FILES_AUTO_DISLIKED])->toBe(1)
        ->and($metrics[MetricsService::KEY_REACTIONS_DISLIKE])->toBe(1);
});

test('keeps an existing dislike reaction while converting the file marker', function () {
    $user = User::factory()->create();
    $file = createLegacyBlacklistedFileForJobTest();
    Reaction::query()->create([
        'file_id' => $file->id,
        'user_id' => $user->id,
        'type' => 'dislike',
    ]);
    mockLegacyConversionIndexSync();

    app(MetricsService::class)->syncAll();

    $job = new ConvertAutoBlacklistsToAutoDislikes($user->id, afterId: 0, chunk: 10, queueName: 'processing');
    app()->call([$job, 'handle']);

    expect(Reaction::query()->where('file_id', $file->id)->where('user_id', $user->id)->count())->toBe(1);

    $metrics = app(MetricsService::class)->getMetrics([
        MetricsService::KEY_FILES_AUTO_DISLIKED,
        MetricsService::KEY_REACTIONS_DISLIKE,
    ]);

    expect($file->fresh()->auto_disliked)->toBeTrue()
        ->and($metrics[MetricsService::KEY_FILES_AUTO_DISLIKED])->toBe(1)
        ->and($metrics[MetricsService::KEY_REACTIONS_DISLIKE])->toBe(1);
});

test('updates an existing positive reaction to dislike during conversion', function () {
    $user = User::factory()->create();
    $file = createLegacyBlacklistedFileForJobTest();
    Reaction::query()->create([
        'file_id' => $file->id,
        'user_id' => $user->id,
        'type' => 'love',
    ]);
    mockLegacyConversionIndexSync();

    app(MetricsService::class)->syncAll();

    $job = new ConvertAutoBlacklistsToAutoDislikes($user->id, afterId: 0, chunk: 10, queueName: 'processing');
    app()->call([$job, 'handle']);

    $reaction = Reaction::query()
        ->where('file_id', $file->id)
        ->where('user_id', $user->id)
        ->first();

    expect($reaction?->type)->toBe('dislike');

    $metrics = app(MetricsService::class)->getMetrics([
        MetricsService::KEY_REACTIONS_LOVE,
        MetricsService::KEY_REACTIONS_DISLIKE,
    ]);

    expect($metrics[MetricsService::KEY_REACTIONS_LOVE])->toBe(0)
        ->and($metrics[MetricsService::KEY_REACTIONS_DISLIKE])->toBe(1);
});

test('skips nonempty legacy reasons because they are plain blacklists', function () {
    $user = User::factory()->create();
    $file = createLegacyBlacklistedFileForJobTest('manual note');

    $job = new ConvertAutoBlacklistsToAutoDislikes($user->id, afterId: 0, chunk: 10, queueName: 'processing');
    app()->call([$job, 'handle']);

    expect($file->fresh()->blacklisted_at)->not->toBeNull()
        ->and($file->fresh()->auto_disliked)->toBeFalse();
    expect(Reaction::query()->where('file_id', $file->id)->exists())->toBeFalse();
});

test('queues the next chunk when a full chunk was scanned', function () {
    Bus::fake();
    $user = User::factory()->create();
    createLegacyBlacklistedFileForJobTest();
    $second = createLegacyBlacklistedFileForJobTest();
    createLegacyBlacklistedFileForJobTest();

    $job = new ConvertAutoBlacklistsToAutoDislikes($user->id, afterId: 0, chunk: 2, queueName: 'maintenance', dryRun: true);
    app()->call([$job, 'handle']);

    Bus::assertDispatched(ConvertAutoBlacklistsToAutoDislikes::class, function (ConvertAutoBlacklistsToAutoDislikes $job) use ($user, $second): bool {
        return $job->userId === $user->id
            && $job->afterId === $second->id
            && $job->chunk === 2
            && $job->queueName === 'maintenance'
            && $job->dryRun === true;
    });
});
