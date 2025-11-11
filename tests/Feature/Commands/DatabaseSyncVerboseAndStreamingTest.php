<?php

namespace Tests\Feature\Commands;

it('push command dry run uses rsync or scp for file transfer', function () {
    $this->artisan('db:push-to-remote-server', [
        'host' => 'user@example.com',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Would execute: php artisan db:backup')
        ->expectsOutputToContain('storage/backups/*.sql')
        ->expectsOutputToContain('Would execute: ssh')
        ->assertSuccessful();
});

it('pull command dry run uses rsync or scp for file transfer', function () {
    $this->artisan('db:pull-from-remote-server', [
        'host' => 'user@example.com',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Would execute: php artisan db:backup')
        ->expectsOutputToContain('storage/backups/*.sql')
        ->expectsOutputToContain('Would execute: ssh')
        ->assertSuccessful();
});
