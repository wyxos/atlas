<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;

test('guests are redirected to the login page', function () {
    $response = $this->get('/dashboard');
    $response->assertRedirect('/login');
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get('/dashboard');
    $response->assertStatus(200);
});

test('guests cannot access cache clear endpoint', function () {
    $response = $this->post('/dashboard/clear-cache');
    $response->assertRedirect('/login');
});

test('authenticated users can clear dashboard cache', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // First, populate the cache
    Cache::put('dashboard.file_stats', ['test' => 'data'], 300);
    expect(Cache::has('dashboard.file_stats'))->toBeTrue();

    // Clear the cache via endpoint
    $response = $this->post('/dashboard/clear-cache');

    $response->assertRedirect(route('dashboard'))
        ->assertSessionHas('success', 'Dashboard cache cleared successfully');

    // Verify cache was cleared
    expect(Cache::has('dashboard.file_stats'))->toBeFalse();
});

test('cache clear endpoint clears cache and redirects', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Populate cache with old data
    $oldStats = ['audioFilesCount' => 999];
    Cache::put('dashboard.file_stats', $oldStats, 300);
    expect(Cache::has('dashboard.file_stats'))->toBeTrue();

    // Clear cache
    $response = $this->post('/dashboard/clear-cache');

    $response->assertRedirect(route('dashboard'))
        ->assertSessionHas('success', 'Dashboard cache cleared successfully');

    // Verify cache was cleared
    expect(Cache::has('dashboard.file_stats'))->toBeFalse();
});
