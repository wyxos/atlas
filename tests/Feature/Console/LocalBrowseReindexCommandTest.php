<?php

use App\Jobs\ReindexLocalBrowseTypesense;
use App\Models\File;
use App\Models\LocalBrowseReindexRun;
use App\Models\Reaction;
use App\Models\User;
use App\Services\Local\LocalBrowseIndexSyncService;
use App\Services\Local\LocalBrowseReindexService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('atlas reindex local browse can queue a tracked run', function () {
    Queue::fake([ReindexLocalBrowseTypesense::class]);

    $this->artisan('atlas:reindex-local-browse --queue --suffix=ops')
        ->expectsOutputToContain('Queued local browse reindex #1 with suffix ops.')
        ->expectsOutputToContain('php artisan atlas:local-browse-reindex-status 1')
        ->assertExitCode(0);

    $run = LocalBrowseReindexRun::query()->first();

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(LocalBrowseReindexRun::STATUS_PENDING)
        ->and($run->phase)->toBe('queued')
        ->and($run->suffix)->toBe('ops');

    Queue::assertPushed(
        ReindexLocalBrowseTypesense::class,
        fn (ReindexLocalBrowseTypesense $job): bool => $job->runId === $run->id
            && $job->queue === 'local-browse-reindex',
    );
});

test('local browse reindex service persists progress and completion', function () {
    $user = User::factory()->create();
    $files = File::factory()->count(2)->create();
    Reaction::query()->create([
        'file_id' => $files->first()->id,
        'user_id' => $user->id,
        'type' => 'like',
    ]);

    $sync = \Mockery::mock(LocalBrowseIndexSyncService::class);
    $sync->shouldReceive('rebuild')
        ->once()
        ->with('tracked', \Mockery::on(function (callable $progress): bool {
            $progress('files', 2);
            $progress('reactions', 1);

            return true;
        }))
        ->andReturn([
            'files_alias' => 'atlas_local_local_browse_files',
            'files_collection' => 'atlas_local_local_browse_files__vtracked',
            'reactions_alias' => 'atlas_local_local_browse_reactions',
            'reactions_collection' => 'atlas_local_local_browse_reactions__vtracked',
            'files_total' => 2,
            'reactions_total' => 1,
        ]);
    $this->app->instance(LocalBrowseIndexSyncService::class, $sync);

    $run = LocalBrowseReindexRun::query()->create([
        'status' => LocalBrowseReindexRun::STATUS_PENDING,
        'phase' => 'queued',
        'suffix' => 'tracked',
    ]);

    app(LocalBrowseReindexService::class)->run($run);

    $run = $run->fresh();

    expect($run->status)->toBe(LocalBrowseReindexRun::STATUS_COMPLETED)
        ->and($run->phase)->toBe('completed')
        ->and($run->files_total)->toBe(2)
        ->and($run->files_indexed)->toBe(2)
        ->and($run->reactions_total)->toBe(1)
        ->and($run->reactions_indexed)->toBe(1)
        ->and($run->files_collection)->toBe('atlas_local_local_browse_files__vtracked')
        ->and($run->reactions_collection)->toBe('atlas_local_local_browse_reactions__vtracked')
        ->and($run->finished_at)->not->toBeNull();
});

test('local browse reindex status reports latest run', function () {
    LocalBrowseReindexRun::query()->create([
        'status' => LocalBrowseReindexRun::STATUS_RUNNING,
        'phase' => 'reactions',
        'suffix' => 'status-test',
        'files_total' => 10,
        'files_indexed' => 10,
        'reactions_total' => 5,
        'reactions_indexed' => 3,
    ]);

    $this->artisan('atlas:local-browse-reindex-status')
        ->expectsOutputToContain('Local browse reindex #1: running (reactions)')
        ->expectsOutputToContain('Files: 10 / 10')
        ->expectsOutputToContain('Reactions: 3 / 5')
        ->assertExitCode(0);
});
