<?php

use App\Enums\ActionType;
use App\Enums\BlacklistPreviewedCountMode;
use App\Jobs\EvaluateContainerAutoBlacklist;
use App\Models\Container;
use App\Models\File;
use App\Models\FileMetadata;
use App\Models\ModerationRule;
use App\Models\Reaction;
use App\Models\User;
use App\Services\FileModerationService;
use App\Services\FilePreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

test('manual file blacklists dispatch container auto blacklist evaluation jobs', function () {
    Bus::fake();

    $user = User::factory()->admin()->create();
    $container = Container::factory()->create(['blacklisted_at' => null]);
    $file = File::factory()->create([
        'blacklisted_at' => null,
        'downloaded' => false,
        'path' => null,
        'preview_path' => null,
        'poster_path' => null,
    ]);
    $file->containers()->attach($container->id);

    $response = $this->actingAs($user)->postJson('/api/files/blacklist/batch', [
        'file_ids' => [$file->id],
    ]);

    $response->assertSuccessful();
    Bus::assertDispatched(EvaluateContainerAutoBlacklist::class, function ($job) use ($container, $user): bool {
        return $job->containerId === $container->id
            && $job->userId === $user->id;
    });
});

test('moderation rule blacklists dispatch container auto blacklist evaluation jobs', function () {
    Bus::fake();

    $user = User::factory()->create();
    $this->actingAs($user);

    ModerationRule::factory()->any(['spam'])->create([
        'active' => true,
        'action_type' => ActionType::BLACKLIST,
    ]);

    $container = Container::factory()->create(['blacklisted_at' => null]);
    $file = File::factory()->create([
        'blacklisted_at' => null,
        'downloaded' => false,
        'path' => null,
        'preview_path' => null,
        'poster_path' => null,
    ]);
    $file->containers()->attach($container->id);
    FileMetadata::factory()->create([
        'file_id' => $file->id,
        'payload' => ['prompt' => 'spam prompt'],
    ]);

    app(FileModerationService::class)->moderate(collect([$file->fresh()->load('metadata')]));

    expect($file->fresh()->blacklisted_at)->not->toBeNull();
    Bus::assertDispatched(EvaluateContainerAutoBlacklist::class, function ($job) use ($container, $user): bool {
        return $job->containerId === $container->id
            && $job->userId === $user->id;
    });
});

test('container evaluation auto blacklists keep containers and unreacted child files after thirty blacklisted items', function () {
    Bus::fake();

    $user = User::factory()->create();
    $container = Container::factory()->create(['blacklisted_at' => null]);
    $blacklistedFiles = createContainerAutoBlacklistFiles(31, ['blacklisted_at' => now()]);
    $positiveFiles = createContainerAutoBlacklistFiles(9);
    $unreactedFiles = createContainerAutoBlacklistFiles(2, ['previewed_count' => 3]);

    attachContainerAutoBlacklistFiles($container, $blacklistedFiles, $positiveFiles, $unreactedFiles);
    reactToContainerAutoBlacklistFiles($positiveFiles, $user);

    (new EvaluateContainerAutoBlacklist((int) $container->id, (int) $user->id))->handle();

    $container->refresh();
    expect($container->blacklisted_at)->not->toBeNull()
        ->and($container->action_type)->toBe(ActionType::BLACKLIST)
        ->and($container->blacklist_previewed_count_mode)->toBe(BlacklistPreviewedCountMode::PRESERVE);

    foreach ($unreactedFiles as $file) {
        $freshFile = $file->fresh();
        expect($freshFile->blacklisted_at)->not->toBeNull()
            ->and($freshFile->auto_blacklisted)->toBeTrue()
            ->and($freshFile->previewed_count)->toBe(3);
    }

    foreach ($positiveFiles as $file) {
        expect($file->fresh()->blacklisted_at)->toBeNull();
    }
});

test('container evaluation uses feed removal after one hundred blacklisted items', function () {
    Bus::fake();

    $user = User::factory()->create();
    $container = Container::factory()->create(['blacklisted_at' => null]);
    $blacklistedFiles = createContainerAutoBlacklistFiles(100, ['blacklisted_at' => now()]);
    $positiveFiles = createContainerAutoBlacklistFiles(9);
    $unreactedFiles = createContainerAutoBlacklistFiles(2, ['previewed_count' => 3]);

    attachContainerAutoBlacklistFiles($container, $blacklistedFiles, $positiveFiles, $unreactedFiles);
    reactToContainerAutoBlacklistFiles($positiveFiles, $user);

    (new EvaluateContainerAutoBlacklist((int) $container->id, (int) $user->id))->handle();

    $container->refresh();
    expect($container->blacklisted_at)->not->toBeNull()
        ->and($container->action_type)->toBe(ActionType::BLACKLIST)
        ->and($container->blacklist_previewed_count_mode)->toBe(BlacklistPreviewedCountMode::FEED_REMOVED);

    foreach ($unreactedFiles as $file) {
        $freshFile = $file->fresh();
        expect($freshFile->blacklisted_at)->not->toBeNull()
            ->and($freshFile->auto_blacklisted)->toBeTrue()
            ->and($freshFile->previewed_count)->toBe(FilePreviewService::FEED_REMOVED_PREVIEW_COUNT);
    }
});

test('container evaluation does not auto blacklist containers with ten positive items', function () {
    Bus::fake();

    $user = User::factory()->create();
    $container = Container::factory()->create(['blacklisted_at' => null]);
    $blacklistedFiles = createContainerAutoBlacklistFiles(100, ['blacklisted_at' => now()]);
    $positiveFiles = createContainerAutoBlacklistFiles(10);
    $unreactedFiles = createContainerAutoBlacklistFiles(2);

    attachContainerAutoBlacklistFiles($container, $blacklistedFiles, $positiveFiles, $unreactedFiles);
    reactToContainerAutoBlacklistFiles($positiveFiles, $user);

    (new EvaluateContainerAutoBlacklist((int) $container->id, (int) $user->id))->handle();

    expect($container->fresh()->blacklisted_at)->toBeNull();

    foreach ($unreactedFiles as $file) {
        expect($file->fresh()->blacklisted_at)->toBeNull();
    }
});

/**
 * @param  array<string, mixed>  $state
 * @return Collection<int, File>
 */
function createContainerAutoBlacklistFiles(int $count, array $state = []): Collection
{
    return File::factory()
        ->count($count)
        ->create([
            'downloaded' => false,
            'path' => null,
            'preview_path' => null,
            'poster_path' => null,
            ...$state,
        ]);
}

/**
 * @param  Collection<int, File>  ...$fileGroups
 */
function attachContainerAutoBlacklistFiles(Container $container, Collection ...$fileGroups): void
{
    foreach ($fileGroups as $files) {
        $container->files()->attach($files->pluck('id')->all());
    }
}

/**
 * @param  Collection<int, File>  $files
 */
function reactToContainerAutoBlacklistFiles(Collection $files, User $user): void
{
    foreach ($files as $file) {
        Reaction::create([
            'file_id' => $file->id,
            'user_id' => $user->id,
            'type' => 'like',
        ]);
    }
}
