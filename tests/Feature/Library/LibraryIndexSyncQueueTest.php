<?php

use App\Jobs\DeleteLibraryFiles;
use App\Jobs\DeleteLibraryIndex;
use App\Jobs\DeleteStoredFileJob;
use App\Jobs\SyncLibraryFileReactions;
use App\Jobs\SyncLibraryFiles;
use App\Jobs\SyncLibraryIndex;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use App\Services\DownloadedFileClearService;
use App\Services\FileBlacklistService;
use App\Services\FilePreviewService;
use App\Services\Library\LibraryIndexSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('syncs file documents with a dedicated job and queue', function () {
    $sync = \Mockery::mock(LibraryIndexSyncService::class);
    $sync->shouldReceive('syncFilesByIds')
        ->once()
        ->with([3, 5])
        ->andReturnNull();

    $job = new SyncLibraryFiles([3, '5', 3, 'invalid']);

    expect($job->fileIds)->toBe([3, 5])
        ->and($job->queue)->toBe('library-file-sync');

    $job->handle($sync);
});

it('syncs reaction documents with a dedicated job and queue', function () {
    $sync = \Mockery::mock(LibraryIndexSyncService::class);
    $sync->shouldReceive('syncReactionsForFileIds')
        ->once()
        ->with([3, 5])
        ->andReturnNull();

    $job = new SyncLibraryFileReactions([3, '5', 3, 'invalid']);

    expect($job->fileIds)->toBe([3, 5])
        ->and($job->queue)->toBe('library-reaction-sync');

    $job->handle($sync);
});

it('deletes file documents with a dedicated job and queue', function () {
    $sync = \Mockery::mock(LibraryIndexSyncService::class);
    $sync->shouldReceive('deleteFilesByIds')
        ->once()
        ->with([3, 5])
        ->andReturnNull();

    $job = new DeleteLibraryFiles([3, '5', 3, 'invalid']);

    expect($job->fileIds)->toBe([3, 5])
        ->and($job->queue)->toBe('library-delete');

    $job->handle($sync);
});

it('deletes the library index with a dedicated job and queue', function () {
    $sync = \Mockery::mock(LibraryIndexSyncService::class);
    $sync->shouldReceive('deleteAll')
        ->once()
        ->andReturnNull();

    $job = new DeleteLibraryIndex;

    expect($job->queue)->toBe('library-delete');

    $job->handle($sync);
});

it('keeps the legacy mixed library sync job compatible with old queued payloads', function () {
    $sync = \Mockery::mock(LibraryIndexSyncService::class);
    $sync->shouldReceive('syncFilesByIds')
        ->once()
        ->with([3, 5])
        ->andReturnNull();
    $sync->shouldReceive('syncReactionsForFileIds')
        ->once()
        ->with([3, 5])
        ->andReturnNull();

    $job = new SyncLibraryIndex([3, '5', 3, 'invalid'], syncFiles: true, syncReactions: true);

    expect($job->fileIds)->toBe([3, 5])
        ->and($job->queue)->toBe('library-sync');

    $job->handle($sync);
});

it('queues file-only library sync when downloaded file state is cleared', function () {
    Queue::fake([DeleteStoredFileJob::class, SyncLibraryFiles::class]);

    $file = File::factory()->create([
        'path' => 'downloads/example.jpg',
        'preview_path' => null,
        'poster_path' => null,
        'downloaded' => true,
        'downloaded_at' => now(),
    ]);

    app(DownloadedFileClearService::class)->clearMany([$file], queueDelete: true);

    Queue::assertPushed(
        SyncLibraryFiles::class,
        fn (SyncLibraryFiles $job): bool => $job->fileIds === [$file->id],
    );
});

it('queues a single file and reaction library sync when files are blacklisted', function () {
    Queue::fake([DeleteStoredFileJob::class, SyncLibraryFiles::class, SyncLibraryFileReactions::class]);

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
        SyncLibraryFiles::class,
        fn (SyncLibraryFiles $job): bool => $job->fileIds === [$file->id],
    );
    Queue::assertPushed(
        SyncLibraryFileReactions::class,
        fn (SyncLibraryFileReactions $job): bool => $job->fileIds === [$file->id],
    );
    Queue::assertPushed(SyncLibraryFiles::class, 1);
    Queue::assertPushed(SyncLibraryFileReactions::class, 1);
});

it('queues file and reaction library sync when preview counts change', function () {
    Queue::fake([DeleteStoredFileJob::class, SyncLibraryFiles::class, SyncLibraryFileReactions::class]);

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'previewed_count' => 0,
        'blacklisted_at' => null,
        'auto_blacklisted' => false,
    ]);

    app(FilePreviewService::class)->increment($file, $user->id);

    Queue::assertPushed(
        SyncLibraryFiles::class,
        fn (SyncLibraryFiles $job): bool => $job->fileIds === [$file->id],
    );
    Queue::assertPushed(
        SyncLibraryFileReactions::class,
        fn (SyncLibraryFileReactions $job): bool => $job->fileIds === [$file->id],
    );
});
