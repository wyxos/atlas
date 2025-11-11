<?php

use App\Jobs\SpotifyIncrementalSync;

it('spotify incremental sync job uses spotify queue', function () {
    $job = new SpotifyIncrementalSync;

    expect($job->queue())->toBe('spotify');
});

