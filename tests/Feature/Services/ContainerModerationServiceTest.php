<?php

use App\Enums\BlacklistPreviewedCountMode;
use App\Jobs\DeleteStoredFileJob;
use App\Models\Container;
use App\Models\File;
use App\Models\Reaction;
use App\Models\Tab;
use App\Models\User;
use App\Services\ContainerModerationService;
use App\Services\FilePreviewService;
use App\Services\Local\LocalBrowseTypesenseGateway;
use App\Services\LocalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(ContainerModerationService::class);
});

test('returns empty arrays for empty file collection', function () {
    $result = $this->service->moderate(collect([]));

    expect($result['flaggedIds'])->toBeEmpty();
    expect($result['processedIds'])->toBeEmpty();
});

test('returns empty arrays when no blacklisted containers exist', function () {
    $file = File::factory()->create();
    Container::factory()->create(['blacklisted_at' => null]);

    $result = $this->service->moderate(collect([$file]));

    expect($result['flaggedIds'])->toBeEmpty();
    expect($result['processedIds'])->toBeEmpty();
});

test('auto blacklists files from blacklisted containers', function () {
    Bus::fake();
    $user = User::factory()->create();
    $this->actingAs($user);

    $container = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'blacklist',
    ]);
    $file = File::factory()->create([
        'auto_blacklisted' => false,
        'blacklisted_at' => null,
        'previewed_count' => 3,
        'path' => null,
        'preview_path' => null,
        'poster_path' => null,
        'downloaded' => false,
        'downloaded_at' => null,
    ]);
    $file->containers()->attach($container->id);

    $result = $this->service->moderate(collect([$file]));

    expect($result['flaggedIds'])->toBeEmpty();
    expect($result['processedIds'])->toContain($file->id);
    expect($file->fresh()->auto_blacklisted)->toBeTrue();
    expect($file->fresh()->blacklisted_at)->not->toBeNull();
    expect($file->fresh()->previewed_count)->toBe(3);
    expect(Reaction::query()->where('file_id', $file->id)->exists())->toBeFalse();
    Bus::assertNothingDispatched();
});

test('blacklisted containers can set previewed count to feed removed count', function () {
    Bus::fake();
    $user = User::factory()->create();
    $this->actingAs($user);

    $container = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'blacklist',
        'blacklist_previewed_count_mode' => BlacklistPreviewedCountMode::FEED_REMOVED,
    ]);
    $file = File::factory()->create([
        'auto_blacklisted' => false,
        'blacklisted_at' => null,
        'previewed_count' => 3,
        'path' => null,
        'preview_path' => null,
        'poster_path' => null,
        'downloaded' => false,
        'downloaded_at' => null,
    ]);
    $file->containers()->attach($container->id);

    $result = $this->service->moderate(collect([$file]));

    expect($result['processedIds'])->toContain($file->id);
    expect($file->fresh()->blacklisted_at)->not->toBeNull();
    expect($file->fresh()->previewed_count)->toBe(FilePreviewService::FEED_REMOVED_PREVIEW_COUNT);
    Bus::assertNothingDispatched();
});

test('feed removed container wins over earlier preserve container', function () {
    Bus::fake();
    $user = User::factory()->create();
    $this->actingAs($user);

    $preserveContainer = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'blacklist',
        'blacklist_previewed_count_mode' => BlacklistPreviewedCountMode::PRESERVE,
    ]);
    $feedRemovedContainer = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'blacklist',
        'blacklist_previewed_count_mode' => BlacklistPreviewedCountMode::FEED_REMOVED,
    ]);
    $file = File::factory()->create([
        'auto_blacklisted' => false,
        'blacklisted_at' => null,
        'previewed_count' => 3,
        'path' => null,
        'preview_path' => null,
        'poster_path' => null,
        'downloaded' => false,
    ]);
    $file->containers()->attach([$preserveContainer->id, $feedRemovedContainer->id]);

    $this->service->moderate(collect([$file]));

    expect($file->fresh()->previewed_count)->toBe(FilePreviewService::FEED_REMOVED_PREVIEW_COUNT);
    Bus::assertNothingDispatched();
});

test('blacklists files for blacklist action type', function () {
    Bus::fake();
    $user = User::factory()->create();
    $this->actingAs($user);

    $container = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'blacklist',
    ]);
    $file = File::factory()->create([
        'auto_blacklisted' => false,
        'blacklisted_at' => null,
        'path' => 'downloads/ab/cd/test.jpg',
        'preview_path' => 'thumbnails/ab/cd/test.jpg',
        'poster_path' => 'posters/ab/cd/test.jpg',
        'downloaded' => true,
        'downloaded_at' => now(),
    ]);
    $file->containers()->attach($container->id);
    $currentTab = Tab::factory()->for($user)->create();
    $otherUserTab = Tab::factory()->for($user)->create();
    $otherUser = User::factory()->create();
    $foreignTab = Tab::factory()->for($otherUser)->create();
    $currentTab->files()->attach($file->id, ['position' => 0]);
    $otherUserTab->files()->attach($file->id, ['position' => 0]);
    $foreignTab->files()->attach($file->id, ['position' => 0]);

    $result = $this->service->moderate(collect([$file]));

    expect($result['flaggedIds'])->toBeEmpty();
    expect($result['processedIds'])->toContain($file->id);
    expect($file->fresh()->blacklisted_at)->not->toBeNull();
    expect($file->fresh()->path)->toBeNull();
    expect($file->fresh()->preview_path)->toBeNull();
    expect($file->fresh()->poster_path)->toBeNull();
    expect($file->fresh()->downloaded)->toBeFalse();
    expect($currentTab->fresh()->files()->where('file_id', $file->id)->exists())->toBeFalse();
    expect($otherUserTab->fresh()->files()->where('file_id', $file->id)->exists())->toBeFalse();
    expect($foreignTab->fresh()->files()->where('file_id', $file->id)->exists())->toBeTrue();

    expect(Reaction::where('file_id', $file->id)->exists())->toBeFalse();

    Bus::assertDispatched(DeleteStoredFileJob::class, function (DeleteStoredFileJob $job) {
        if (! is_array($job->filePath)) {
            return false;
        }

        $paths = $job->filePath;
        sort($paths);

        return $paths === [
            'downloads/ab/cd/test.jpg',
            'posters/ab/cd/test.jpg',
            'thumbnails/ab/cd/test.jpg',
        ];
    });

    mock(LocalBrowseTypesenseGateway::class)
        ->shouldReceive('search')
        ->once()
        ->andReturn([
            'files' => [$file->fresh()],
            'metadata' => [
                'nextCursor' => null,
                'total' => 1,
            ],
        ]);

    $localBrowse = app(LocalService::class)->fetch([
        'blacklisted' => 'yes',
    ]);

    expect(collect($localBrowse['files'])->pluck('id'))->toContain($file->id);
});

test('skips blacklisting files from blacklisted containers when any reaction already exists', function () {
    Bus::fake();

    $reactionUser = User::factory()->create();
    $container = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'blacklist',
    ]);
    $file = File::factory()->create([
        'auto_blacklisted' => false,
        'blacklisted_at' => null,
        'path' => 'downloads/reacted-container.jpg',
    ]);
    $file->containers()->attach($container->id);

    Reaction::create([
        'file_id' => $file->id,
        'user_id' => $reactionUser->id,
        'type' => 'like',
    ]);

    $result = $this->service->moderate(collect([$file]));

    expect($result['flaggedIds'])->toBeEmpty();
    expect($result['processedIds'])->toBeEmpty();
    expect($file->fresh()->blacklisted_at)->toBeNull();

    Bus::assertNothingDispatched();
});

test('skips files already auto-blacklisted', function () {
    $container = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'blacklist',
    ]);
    $file = File::factory()->create([
        'auto_blacklisted' => true,
        'blacklisted_at' => now(),
    ]);
    $file->containers()->attach($container->id);

    $result = $this->service->moderate(collect([$file]));

    expect($result['flaggedIds'])->toBeEmpty();
    expect($result['processedIds'])->toBeEmpty();
});

test('skips files already blacklisted', function () {
    $container = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'blacklist',
    ]);
    $file = File::factory()->create([
        'auto_blacklisted' => false,
        'blacklisted_at' => now(),
    ]);
    $file->containers()->attach($container->id);

    $result = $this->service->moderate(collect([$file]));

    expect($result['flaggedIds'])->toBeEmpty();
    expect($result['processedIds'])->toBeEmpty();
});

test('skips files without containers', function () {
    Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'blacklist',
    ]);
    $file = File::factory()->create([
        'auto_blacklisted' => false,
        'blacklisted_at' => null,
    ]);

    $result = $this->service->moderate(collect([$file]));

    expect($result['flaggedIds'])->toBeEmpty();
    expect($result['processedIds'])->toBeEmpty();
});

test('does not dispatch delete job when file has no path', function () {
    Bus::fake();

    $container = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'blacklist',
    ]);
    $file = File::factory()->create([
        'auto_blacklisted' => false,
        'blacklisted_at' => null,
        'path' => null,
        'preview_path' => null,
        'poster_path' => null,
        'downloaded' => false,
        'downloaded_at' => null,
    ]);
    $file->containers()->attach($container->id);

    $result = $this->service->moderate(collect([$file]));

    expect($result['processedIds'])->toContain($file->id);
    expect($file->fresh()->blacklisted_at)->not->toBeNull();

    // Assert DeleteStoredFileJob was not dispatched
    Bus::assertNotDispatched(DeleteStoredFileJob::class);

});

test('handles multiple files with different action types', function () {
    Bus::fake();
    $user = User::factory()->create();
    $this->actingAs($user);

    $container1 = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'blacklist',
    ]);
    $container2 = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'blacklist',
    ]);
    $container3 = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'blacklist',
    ]);

    $file1 = File::factory()->create(['auto_blacklisted' => false, 'blacklisted_at' => null]);
    $file1->containers()->attach($container1->id);

    $file2 = File::factory()->create(['auto_blacklisted' => false, 'blacklisted_at' => null, 'path' => 'downloads/test2.jpg']);
    $file2->containers()->attach($container2->id);

    $file3 = File::factory()->create(['auto_blacklisted' => false, 'blacklisted_at' => null, 'path' => 'downloads/test3.jpg']);
    $file3->containers()->attach($container3->id);

    $result = $this->service->moderate(collect([$file1, $file2, $file3]));

    expect($result['flaggedIds'])->toBeEmpty();
    expect($result['processedIds'])->toContain($file1->id);
    expect($result['processedIds'])->toContain($file2->id);
    expect($result['processedIds'])->toContain($file3->id);
    expect($file1->fresh()->auto_blacklisted)->toBeTrue();
    expect($file1->fresh()->blacklisted_at)->not->toBeNull();
    expect($file2->fresh()->blacklisted_at)->not->toBeNull();
    expect($file3->fresh()->blacklisted_at)->not->toBeNull();
});

test('handles files with multiple containers', function () {
    Bus::fake();
    $user = User::factory()->create();
    $this->actingAs($user);

    $container1 = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'blacklist',
    ]);
    $container2 = Container::factory()->create([
        'blacklisted_at' => null,
    ]);

    $file = File::factory()->create(['auto_blacklisted' => false, 'blacklisted_at' => null]);
    $file->containers()->attach([$container1->id, $container2->id]);

    $result = $this->service->moderate(collect([$file]));

    expect($result['flaggedIds'])->toBeEmpty();
    expect($result['processedIds'])->toContain($file->id);
    expect($file->fresh()->auto_blacklisted)->toBeTrue();
    expect($file->fresh()->blacklisted_at)->not->toBeNull();
});
