<?php

use App\Jobs\NormalizeReferrerUrls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

test('atlas:normalize-referrer-urls dispatches the initial queued job', function () {
    Bus::fake();

    $this->artisan('atlas:normalize-referrer-urls https://www.example.com/path --strip-query-param=* --chunk=250 --queue=maintenance --start-id=42')
        ->assertExitCode(0);

    Bus::assertDispatched(NormalizeReferrerUrls::class, function (NormalizeReferrerUrls $job): bool {
        return $job->domain === 'www.example.com'
            && $job->queryParamsToStrip === ['*']
            && $job->afterId === 42
            && $job->chunk === 250
            && $job->queueName === 'maintenance';
    });
});

test('atlas:normalize-referrer-urls requires at least one cleanup rule', function () {
    Bus::fake();

    $this->artisan('atlas:normalize-referrer-urls example.com')
        ->assertExitCode(1);

    Bus::assertNothingDispatched();
});
