<?php

use App\Jobs\RepairReactedBlacklistedFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

test('atlas:repair-reacted-blacklisted-files dispatches the initial queued job', function () {
    Bus::fake();

    $this->artisan('atlas:repair-reacted-blacklisted-files --chunk=75 --queue=maintenance --start-id=21 --max-files=9 --dry-run')
        ->assertExitCode(0);

    Bus::assertDispatched(RepairReactedBlacklistedFiles::class, function (RepairReactedBlacklistedFiles $job): bool {
        return $job->afterId === 21
            && $job->chunk === 75
            && $job->queueName === 'maintenance'
            && $job->remaining === 9
            && $job->dryRun === true;
    });
});

test('atlas:repair-reacted-blacklisted-files uses defaults when optional flags are omitted', function () {
    Bus::fake();

    $this->artisan('atlas:repair-reacted-blacklisted-files')
        ->assertExitCode(0);

    Bus::assertDispatched(RepairReactedBlacklistedFiles::class, function (RepairReactedBlacklistedFiles $job): bool {
        return $job->afterId === 0
            && $job->chunk === 100
            && $job->queueName === 'processing'
            && $job->remaining === 0
            && $job->dryRun === false;
    });
});
