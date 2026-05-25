<?php

it('runs the production library sync supervisor with enough workers to drain file index updates', function () {
    $horizon = require dirname(__DIR__, 2).'/config/horizon.php';

    expect($horizon['environments']['production']['supervisor-library-sync']['maxProcesses'])->toBe(6)
        ->and($horizon['defaults']['supervisor-library-sync']['queue'])->toBe(['library-sync']);
});
