<?php

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create test files with different mime types
    File::factory()->create(['mime_type' => 'audio/mp3']);
    File::factory()->create(['mime_type' => 'audio/wav']);
    File::factory()->create(['mime_type' => 'video/mp4']);
    File::factory()->create(['mime_type' => 'image/jpeg']);
    File::factory()->create(['mime_type' => 'application/pdf']);
});

test('dashboard displays file statistics', function () {
    // Create and authenticate a user
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get('/dashboard');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) =>
        $page->component('Dashboard')
            ->has('fileStats', fn ($stats) =>
                $stats->has('totalFiles')
                    ->has('audioFiles')
                    ->has('videoFiles')
                    ->has('imageFiles')
                    ->has('otherFiles')
                    ->where('totalFiles', 5)
                    ->where('audioFiles', 2)
                    ->where('videoFiles', 1)
                    ->where('imageFiles', 1)
                    ->where('otherFiles', 1)
            )
    );
});

test('file statistics endpoint returns correct data', function () {
    // Create and authenticate a user
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get('/dashboard/file-stats');

    $response->assertStatus(200);
    $response->assertJson([
        'totalFiles' => 5,
        'audioFiles' => 2,
        'videoFiles' => 1,
        'imageFiles' => 1,
        'otherFiles' => 1,
    ]);
});

test('only super admin can access users list', function () {
    // Create a regular user
    $regularUser = User::factory()->create([
        'is_super_admin' => false
    ]);

    // Create a super admin user
    $superAdmin = User::factory()->create([
        'is_super_admin' => true
    ]);

    // Regular user should be denied access
    $this->actingAs($regularUser)
        ->get('/users')
        ->assertStatus(403);

    // Super admin should have access
    $this->actingAs($superAdmin)
        ->get('/users')
        ->assertStatus(200);
});
