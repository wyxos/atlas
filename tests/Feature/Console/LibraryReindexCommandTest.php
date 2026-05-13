<?php

use App\Jobs\ReindexLibraryTypesense;
use App\Models\File;
use App\Models\LibraryReindexRun;
use App\Models\Reaction;
use App\Models\User;
use App\Services\Library\LibraryIndexSyncService;
use App\Services\Library\LibraryReindexService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('atlas reindex library can queue a tracked run', function () {
    Queue::fake([ReindexLibraryTypesense::class]);

    $this->artisan('atlas:reindex-library --queue --suffix=ops')
        ->expectsOutputToContain('Queued library reindex #1 with suffix ops.')
        ->expectsOutputToContain('php artisan atlas:library-reindex-status 1')
        ->assertExitCode(0);

    $run = LibraryReindexRun::query()->first();

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(LibraryReindexRun::STATUS_PENDING)
        ->and($run->phase)->toBe('queued')
        ->and($run->suffix)->toBe('ops');

    Queue::assertPushed(
        ReindexLibraryTypesense::class,
        fn (ReindexLibraryTypesense $job): bool => $job->runId === $run->id
            && $job->queue === 'library-reindex',
    );
});

test('library reindex service persists progress and completion', function () {
    $user = User::factory()->create();
    $files = File::factory()->count(2)->create();
    Reaction::query()->create([
        'file_id' => $files->first()->id,
        'user_id' => $user->id,
        'type' => 'like',
    ]);

    $sync = \Mockery::mock(LibraryIndexSyncService::class);
    $sync->shouldReceive('rebuild')
        ->once()
        ->with('tracked', \Mockery::on(function (callable $progress): bool {
            $progress('files', 2);
            $progress('reactions', 1);

            return true;
        }))
        ->andReturn([
            'files_alias' => 'atlas_local_library_files',
            'files_collection' => 'atlas_local_library_files__vtracked',
            'reactions_alias' => 'atlas_local_library_reactions',
            'reactions_collection' => 'atlas_local_library_reactions__vtracked',
            'files_total' => 2,
            'reactions_total' => 1,
        ]);
    $this->app->instance(LibraryIndexSyncService::class, $sync);

    $run = LibraryReindexRun::query()->create([
        'status' => LibraryReindexRun::STATUS_PENDING,
        'phase' => 'queued',
        'suffix' => 'tracked',
    ]);

    app(LibraryReindexService::class)->run($run);

    $run = $run->fresh();

    expect($run->status)->toBe(LibraryReindexRun::STATUS_COMPLETED)
        ->and($run->phase)->toBe('completed')
        ->and($run->files_total)->toBe(2)
        ->and($run->files_indexed)->toBe(2)
        ->and($run->reactions_total)->toBe(1)
        ->and($run->reactions_indexed)->toBe(1)
        ->and($run->files_collection)->toBe('atlas_local_library_files__vtracked')
        ->and($run->reactions_collection)->toBe('atlas_local_library_reactions__vtracked')
        ->and($run->finished_at)->not->toBeNull();
});

test('library reindex status reports latest run', function () {
    LibraryReindexRun::query()->create([
        'status' => LibraryReindexRun::STATUS_RUNNING,
        'phase' => 'reactions',
        'suffix' => 'status-test',
        'files_total' => 10,
        'files_indexed' => 10,
        'reactions_total' => 5,
        'reactions_indexed' => 3,
    ]);

    $this->artisan('atlas:library-reindex-status')
        ->expectsOutputToContain('Library reindex #1: running (reactions)')
        ->expectsOutputToContain('Files: 10 / 10')
        ->expectsOutputToContain('Reactions: 3 / 5')
        ->assertExitCode(0);
});
