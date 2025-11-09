<?php

use App\Models\User;
use Database\Seeders\LocalDemoUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('creates a demo admin user when running locally', function () {
    $originalEnv = app()->environment();
    app()->detectEnvironment(fn () => 'local');

    try {
        app(LocalDemoUserSeeder::class)->run();

        $user = User::query()->where('email', 'demo.admin@atlas.test')->first();

        expect($user)->not->toBeNull();
        expect($user->is_admin)->toBeTrue();
        expect($user->name)->toBe('Atlas Demo Admin');
        expect(Hash::check('demo-admin-password', $user->password))->toBeTrue();
    } finally {
        app()->detectEnvironment(fn () => $originalEnv);
    }
});

it('skips creating the demo admin user outside the local environment', function () {
    $originalEnv = app()->environment();
    app()->detectEnvironment(fn () => 'production');

    try {
        app(LocalDemoUserSeeder::class)->run();

        expect(User::query()->where('email', 'demo.admin@atlas.test')->exists())->toBeFalse();
    } finally {
        app()->detectEnvironment(fn () => $originalEnv);
    }
});
