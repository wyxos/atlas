<?php

use App\Enums\DownloadTransferStatus;
use App\Jobs\Downloads\RemoveDownloadTransfers;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('prunes completed download list rows older than the configured retention', function () {
    config()->set('downloads.auto_remove_completed_after', 24);

    $oldCompleted = makePruneTestTransfer(DownloadTransferStatus::COMPLETED, now()->subHours(25));
    $recentCompleted = makePruneTestTransfer(DownloadTransferStatus::COMPLETED, now()->subHours(23));
    $missingFinishedAt = makePruneTestTransfer(DownloadTransferStatus::COMPLETED, null);
    $oldFailed = makePruneTestTransfer(DownloadTransferStatus::FAILED, now()->subHours(25));
    $active = makePruneTestTransfer(DownloadTransferStatus::DOWNLOADING, now()->subHours(25));

    $this->artisan('atlas:prune-completed-download')
        ->expectsOutput('Pruned 1 completed download transfer(s).')
        ->assertExitCode(0);

    expect(DownloadTransfer::query()->whereKey($oldCompleted->id)->exists())->toBeFalse()
        ->and(DownloadTransfer::query()->whereKey($recentCompleted->id)->exists())->toBeTrue()
        ->and(DownloadTransfer::query()->whereKey($missingFinishedAt->id)->exists())->toBeTrue()
        ->and(DownloadTransfer::query()->whereKey($oldFailed->id)->exists())->toBeTrue()
        ->and(DownloadTransfer::query()->whereKey($active->id)->exists())->toBeTrue()
        ->and(File::query()->whereKey($oldCompleted->file_id)->exists())->toBeTrue();
});

it('reports eligible completed download rows during dry runs without pruning', function () {
    config()->set('downloads.auto_remove_completed_after', 24);

    $oldCompleted = makePruneTestTransfer(DownloadTransferStatus::COMPLETED, now()->subHours(25));
    $recentCompleted = makePruneTestTransfer(DownloadTransferStatus::COMPLETED, now()->subHours(23));

    $this->artisan('atlas:prune-completed-download --dry-run')
        ->expectsOutput('Would prune 1 completed download transfer(s).')
        ->assertExitCode(0);

    expect(DownloadTransfer::query()->whereKey($oldCompleted->id)->exists())->toBeTrue()
        ->and(DownloadTransfer::query()->whereKey($recentCompleted->id)->exists())->toBeTrue();
});

it('can disable automatic completed download pruning with a non-positive retention', function () {
    config()->set('downloads.auto_remove_completed_after', 0);

    $oldCompleted = makePruneTestTransfer(DownloadTransferStatus::COMPLETED, now()->subHours(25));

    $this->artisan('atlas:prune-completed-download')
        ->expectsOutput('Automatic completed download pruning is disabled.')
        ->assertExitCode(0);

    expect(DownloadTransfer::query()->whereKey($oldCompleted->id)->exists())->toBeTrue();
});

it('queues large completed download prune runs with the cutoff', function () {
    Bus::fake();

    config()->set('downloads.auto_remove_completed_after', 24);
    config()->set('downloads.bulk_removal_sync_limit', 1);

    makePruneTestTransfer(DownloadTransferStatus::COMPLETED, now()->subHours(25));
    makePruneTestTransfer(DownloadTransferStatus::COMPLETED, now()->subHours(26));

    $this->artisan('atlas:prune-completed-download')
        ->expectsOutput('Queued pruning for 2 completed download transfer(s).')
        ->assertExitCode(0);

    Bus::assertDispatched(RemoveDownloadTransfers::class, function (RemoveDownloadTransfers $job): bool {
        return $job->completedOnly === true
            && is_string($job->completedBefore)
            && $job->alsoFromDisk === false
            && $job->alsoDeleteRecord === false;
    });
});

function makePruneTestTransfer(string $status, mixed $finishedAt): DownloadTransfer
{
    $user = User::factory()->create();
    $file = File::factory()->create([
        'url' => 'https://example.com/prune-'.$status.'-'.$user->id.'.bin',
        'downloaded' => $status === DownloadTransferStatus::COMPLETED,
        'path' => $status === DownloadTransferStatus::COMPLETED
            ? 'downloads/prune-'.$status.'-'.$user->id.'.bin'
            : null,
        'downloaded_at' => $status === DownloadTransferStatus::COMPLETED ? $finishedAt : null,
    ]);

    return DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => (string) $file->url,
        'domain' => 'example.com',
        'status' => $status,
        'bytes_total' => 100,
        'bytes_downloaded' => $status === DownloadTransferStatus::COMPLETED ? 100 : 10,
        'last_broadcast_percent' => $status === DownloadTransferStatus::COMPLETED ? 100 : 10,
        'finished_at' => $finishedAt,
        'failed_at' => $status === DownloadTransferStatus::FAILED ? $finishedAt : null,
    ]);
}
