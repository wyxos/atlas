<?php

use App\Jobs\BackfillDeviantArtContainers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

test('atlas:backfill-deviantart-containers dispatches the initial queued job', function () {
    Bus::fake();

    $this->artisan('atlas:backfill-deviantart-containers --chunk=250 --queue=maintenance --start-id=42')
        ->assertExitCode(0);

    Bus::assertDispatched(BackfillDeviantArtContainers::class, function (BackfillDeviantArtContainers $job): bool {
        return $job->afterId === 42
            && $job->chunk === 250
            && $job->queueName === 'maintenance';
    });
});
