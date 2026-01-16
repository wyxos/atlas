<?php

use Illuminate\Support\Facades\Artisan;

it('prints queues from Horizon config in dry run', function () {
    config()->set('horizon.defaults', [
        'supervisor-1' => [
            'queue' => ['default', 'emails'],
        ],
        'supervisor-2' => [
            'queue' => 'imports,notifications',
        ],
    ]);
    config()->set('horizon.environments.testing', [
        'supervisor-1' => [
            'queue' => ['priority'],
        ],
    ]);

    $exitCode = Artisan::call('queue:work-horizon', ['--dry-run' => true]);

    expect($exitCode)->toBe(0);
    expect(Artisan::output())->toContain('Using queues: default,emails,imports,notifications,priority');
});

it('fails when Horizon config has no queues', function () {
    config()->set('horizon.defaults', []);
    config()->set('horizon.environments.testing', []);

    $exitCode = Artisan::call('queue:work-horizon', ['--dry-run' => true]);

    expect($exitCode)->toBe(1);
    expect(Artisan::output())->toContain('No queues found in Horizon config for environment [testing].');
});
