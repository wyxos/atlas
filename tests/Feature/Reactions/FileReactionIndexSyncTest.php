<?php

use App\Jobs\DownloadFile;
use App\Jobs\SyncLocalBrowseIndex;
use App\Models\File;
use App\Models\User;
use App\Services\FileReactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('syncs browse file and reaction projections when a reaction is added', function () {
    Queue::fake([DownloadFile::class, SyncLocalBrowseIndex::class]);

    $user = User::factory()->create();
    $file = File::factory()->create([
        'downloaded' => true,
        'blacklisted_at' => null,
        'auto_blacklisted' => false,
    ]);

    $this->actingAs($user);

    app(FileReactionService::class)->toggle($file, $user, 'like');

    Queue::assertPushed(
        SyncLocalBrowseIndex::class,
        fn (SyncLocalBrowseIndex $job): bool => $job->fileIds === [$file->id]
            && $job->syncFiles
            && $job->syncReactions
            && $job->queue === 'local-browse-sync',
    );
});

it('syncs browse file and reaction projections when a reaction is removed', function () {
    Queue::fake([DownloadFile::class, SyncLocalBrowseIndex::class]);

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
        SyncLocalBrowseIndex::class,
        fn (SyncLocalBrowseIndex $job): bool => $job->fileIds === [$file->id]
            && $job->syncFiles
            && $job->syncReactions,
    );
    Queue::assertPushed(SyncLocalBrowseIndex::class, 2);
});
