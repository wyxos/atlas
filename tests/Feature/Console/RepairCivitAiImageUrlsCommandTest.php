<?php

use App\Jobs\RepairCivitAiImageUrls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

test('atlas:repair-civitai-image-urls dispatches the initial queued job', function () {
    Bus::fake();

    $this->artisan('atlas:repair-civitai-image-urls --chunk=250 --queue=maintenance --start-id=42 --max-files=17 --dry-run')
        ->assertExitCode(0);

    Bus::assertDispatched(RepairCivitAiImageUrls::class, function (RepairCivitAiImageUrls $job): bool {
        return $job->afterId === 42
            && $job->chunk === 250
            && $job->queueName === 'maintenance'
            && $job->remaining === 17
            && $job->dryRun === true;
    });
});

test('atlas:repair-civitai-image-urls uses defaults when optional flags are omitted', function () {
    Bus::fake();

    $this->artisan('atlas:repair-civitai-image-urls')
        ->assertExitCode(0);

    Bus::assertDispatched(RepairCivitAiImageUrls::class, function (RepairCivitAiImageUrls $job): bool {
        return $job->afterId === 0
            && $job->chunk === 500
            && $job->queueName === 'processing'
            && $job->remaining === 0
            && $job->dryRun === false;
    });
});
