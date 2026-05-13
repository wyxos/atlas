<?php

use App\Jobs\DownloadFile;
use App\Jobs\SyncLibraryIndex;
use App\Models\File;
use App\Models\User;
use App\Services\FileReactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('syncs library file and reaction projections when a reaction is added', function () {
    Queue::fake([DownloadFile::class, SyncLibraryIndex::class]);

    $user = User::factory()->create();
    $file = File::factory()->create([
        'downloaded' => true,
        'blacklisted_at' => null,
        'auto_blacklisted' => false,
    ]);

    $this->actingAs($user);

    app(FileReactionService::class)->toggle($file, $user, 'like');

    Queue::assertPushed(
        SyncLibraryIndex::class,
        fn (SyncLibraryIndex $job): bool => $job->fileIds === [$file->id]
            && $job->syncFiles
            && $job->syncReactions
            && $job->queue === 'library-sync',
    );
});

it('syncs library file and reaction projections when a reaction is removed', function () {
    Queue::fake([DownloadFile::class, SyncLibraryIndex::class]);

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
        SyncLibraryIndex::class,
        fn (SyncLibraryIndex $job): bool => $job->fileIds === [$file->id]
            && $job->syncFiles
            && $job->syncReactions,
    );
    Queue::assertPushed(SyncLibraryIndex::class, 2);
});
