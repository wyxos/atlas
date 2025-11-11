<?php

use App\Jobs\SpotifyScanJob;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

it('dispatches spotify scan job to spotify queue', function () {
    Bus::fake();

    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('spotify.scan.start'));

    Bus::assertDispatched(SpotifyScanJob::class, function ($job) use ($user) {
        return $job->userId === $user->id
            && $job->queue() === 'spotify';
    });
});

