<?php

use App\Jobs\DeleteStoredFileJob;
use App\Jobs\SyncLocalBrowseIndex;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use App\Services\DownloadedFileClearService;
use App\Services\FileBlacklistService;
use App\Services\FilePreviewService;
use App\Services\Local\LocalBrowseIndexSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('sync local browse index job normalizes ids and syncs selected projections', function () {
    $sync = \Mockery::mock(LocalBrowseIndexSyncService::class);
    $sync->shouldReceive('syncFilesByIds')
        ->once()
        ->with([3, 5])
        ->andReturnNull();
    $sync->shouldReceive('syncReactionsForFileIds')
        ->once()
        ->with([3, 5])
        ->andReturnNull();

    $job = new SyncLocalBrowseIndex([3, '5', 3, 'invalid'], syncFiles: true, syncReactions: true);

    expect($job->fileIds)->toBe([3, 5])
        ->and($job->queue)->toBe('local-browse-sync');

    $job->handle($sync);
});

it('queues file-only local browse sync when downloaded file state is cleared', function () {
    Queue::fake([DeleteStoredFileJob::class, SyncLocalBrowseIndex::class]);

    $file = File::factory()->create([
        'path' => 'downloads/example.jpg',
        'preview_path' => null,
        'poster_path' => null,
        'downloaded' => true,
        'downloaded_at' => now(),
    ]);

    app(DownloadedFileClearService::class)->clearMany([$file], queueDelete: true);

    Queue::assertPushed(
        SyncLocalBrowseIndex::class,
        fn (SyncLocalBrowseIndex $job): bool => $job->fileIds === [$file->id]
            && $job->syncFiles
            && ! $job->syncReactions,
    );
});

it('queues a single file and reaction local browse sync when files are blacklisted', function () {
    Queue::fake([DeleteStoredFileJob::class, SyncLocalBrowseIndex::class]);

    $user = User::factory()->create();
    $file = File::factory()->create([
        'blacklisted_at' => null,
        'path' => 'downloads/blacklist-me.jpg',
        'preview_path' => null,
        'poster_path' => null,
        'downloaded' => true,
        'downloaded_at' => now(),
    ]);
    Reaction::query()->create([
        'file_id' => $file->id,
        'user_id' => $user->id,
        'type' => 'like',
    ]);

    app(FileBlacklistService::class)->apply([$file], $user->id, queueDelete: true);

    Queue::assertPushed(
        SyncLocalBrowseIndex::class,
        fn (SyncLocalBrowseIndex $job): bool => $job->fileIds === [$file->id]
            && $job->syncFiles
            && $job->syncReactions,
    );
    Queue::assertPushed(SyncLocalBrowseIndex::class, 1);
});

it('queues file and reaction local browse sync when preview counts change', function () {
    Queue::fake([DeleteStoredFileJob::class, SyncLocalBrowseIndex::class]);

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'previewed_count' => 0,
        'blacklisted_at' => null,
        'auto_blacklisted' => false,
    ]);

    app(FilePreviewService::class)->increment($file, $user->id);

    Queue::assertPushed(
        SyncLocalBrowseIndex::class,
        fn (SyncLocalBrowseIndex $job): bool => $job->fileIds === [$file->id]
            && $job->syncFiles
            && $job->syncReactions,
    );
});
