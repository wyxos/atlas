<?php

use App\Jobs\DownloadFile;
use App\Jobs\SyncLibraryFileReactions;
use App\Jobs\SyncLibraryFiles;
use App\Models\File;
use App\Models\User;
use App\Services\FileReactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('syncs library file and reaction projections when a reaction is added', function () {
    Queue::fake([DownloadFile::class, SyncLibraryFiles::class, SyncLibraryFileReactions::class]);

    $user = User::factory()->create();
    $file = File::factory()->create([
        'downloaded' => true,
        'blacklisted_at' => null,
        'auto_blacklisted' => false,
    ]);

    $this->actingAs($user);

    app(FileReactionService::class)->toggle($file, $user, 'like');

    Queue::assertPushed(
        SyncLibraryFiles::class,
        fn (SyncLibraryFiles $job): bool => $job->fileIds === [$file->id]
            && $job->queue === 'library-file-sync',
    );
    Queue::assertPushed(
        SyncLibraryFileReactions::class,
        fn (SyncLibraryFileReactions $job): bool => $job->fileIds === [$file->id]
            && $job->queue === 'library-reaction-sync',
    );
});

it('syncs library file and reaction projections when a reaction is removed', function () {
    Queue::fake([DownloadFile::class, SyncLibraryFiles::class, SyncLibraryFileReactions::class]);

    $user = User::factory()->create();
    $file = File::factory()->create([
        'downloaded' => true,
        'blacklisted_at' => null,
        'auto_blacklisted' => false,
    ]);

    $this->actingAs($user);

    $service = app(FileReactionService::class);

    $service->toggle($file, $user, 'like');
    $service->toggle($file, $user, 'like');

    Queue::assertPushed(
        SyncLibraryFiles::class,
        fn (SyncLibraryFiles $job): bool => $job->fileIds === [$file->id],
    );
    Queue::assertPushed(
        SyncLibraryFileReactions::class,
        fn (SyncLibraryFileReactions $job): bool => $job->fileIds === [$file->id],
    );
    Queue::assertPushed(SyncLibraryFiles::class, 2);
    Queue::assertPushed(SyncLibraryFileReactions::class, 2);
});

it('can defer library projection sync for batch callers', function () {
    Queue::fake([DownloadFile::class, SyncLibraryFiles::class, SyncLibraryFileReactions::class]);

    $user = User::factory()->create();
    $file = File::factory()->create([
        'downloaded' => true,
        'blacklisted_at' => null,
        'auto_blacklisted' => false,
    ]);

    app(FileReactionService::class)->set($file, $user, 'love', [
        'queueDownload' => false,
        'queueLibrarySync' => false,
    ]);

    Queue::assertNotPushed(SyncLibraryFiles::class);
    Queue::assertNotPushed(SyncLibraryFileReactions::class);
});
