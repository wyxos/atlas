<?php

use Illuminate\Support\Facades\Http;

it('browse page renders with no smoke', function () {
    // Stub external API calls for deterministic, offline-friendly test
    Http::fake([
        'civitai.com/api/v1/images*' => Http::response([
            'items' => [],
            'metadata' => ['nextCursor' => null],
        ], 200),
    ]);

    // Create and authenticate a user, as /browse is behind auth
    $user = \App\Models\User::factory()->create();

    $page = visit(route('login'))
        ->type('Email address', $user->email)
        ->type('Password', 'password')
        ->press('Log in');

    // Navigate to browse
    $page = visit(route('browse'));
    $page->assertNoSmoke();
    $page->assertSee('Browse');
});
