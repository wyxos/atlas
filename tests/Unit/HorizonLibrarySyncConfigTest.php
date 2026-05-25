<?php

it('runs file library sync on a dedicated production queue with enough workers', function () {
    $horizon = require dirname(__DIR__, 2).'/config/horizon.php';

    expect($horizon['environments']['production']['supervisor-library-file-sync']['minProcesses'])->toBe(6)
        ->and($horizon['environments']['production']['supervisor-library-file-sync']['maxProcesses'])->toBe(6)
        ->and($horizon['defaults']['supervisor-library-file-sync']['queue'])->toBe(['library-file-sync'])
        ->and($horizon['defaults']['supervisor-library-reaction-sync']['queue'])->toBe(['library-reaction-sync'])
        ->and($horizon['defaults']['supervisor-library-delete']['queue'])->toBe(['library-delete'])
        ->and($horizon['environments']['production']['supervisor-library-sync']['maxProcesses'])->toBe(1);
});
