<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('app:setup creates an administrator', function () {
    $this->artisan('app:setup')
        ->expectsQuestion('Name', 'Admin User')
        ->expectsQuestion('Email', 'admin@atlas.test')
        ->expectsQuestion('Password (leave blank to generate a secure one)', 'Str0ng!Passw0rd123')
        ->expectsQuestion('Confirm password', 'Str0ng!Passw0rd123')
        ->expectsOutput('Administrator created successfully.')
        ->assertExitCode(0);

    $user = User::where('email', 'admin@atlas.test')->first();

    expect($user)->not->toBeNull();
    expect($user->name)->toBe('Admin User');
    expect($user->is_admin)->toBeTrue();
    expect($user->email_verified_at)->not->toBeNull();
});

test('app:setup rejects weak passwords', function () {
    $this->artisan('app:setup')
        ->expectsQuestion('Name', 'Admin User')
        ->expectsQuestion('Email', 'admin2@atlas.test')
        ->expectsQuestion('Password (leave blank to generate a secure one)', 'password')
        ->expectsQuestion('Confirm password', 'password')
        ->expectsOutputToContain('password')
        ->expectsQuestion('Password', 'Str0ng!Passw0rd123')
        ->expectsQuestion('Confirm password', 'Str0ng!Passw0rd123')
        ->expectsOutput('Administrator created successfully.')
        ->assertExitCode(0);

    expect(User::where('email', 'admin2@atlas.test')->exists())->toBeTrue();
});
