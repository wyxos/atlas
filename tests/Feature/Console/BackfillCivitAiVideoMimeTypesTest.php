<?php

use App\Jobs\BackfillCivitAiVideoMimeTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

test('atlas:backfill-civitai-video-mime-types dispatches the initial queued job', function () {
    Bus::fake();

    $this->artisan('atlas:backfill-civitai-video-mime-types --chunk=250 --queue=maintenance --start-id=42')
        ->assertExitCode(0);

    Bus::assertDispatched(BackfillCivitAiVideoMimeTypes::class, function (BackfillCivitAiVideoMimeTypes $job): bool {
        return $job->afterId === 42
            && $job->chunk === 250
            && $job->queueName === 'maintenance';
    });
});
