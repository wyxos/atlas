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
                $stats->has('audioFilesCount')
                    ->has('audioSpaceUsed')
                    ->has('audioNotFound')
                    ->has('audioWithMetadata')
                    ->has('audioWithoutMetadata')
                    ->has('audioMetadataReviewRequired')
                    ->has('audioMetadataReviewNotRequired')
                    ->has('globalWithMetadata')
                    ->has('globalWithoutMetadata')
                    ->has('globalMetadataReviewRequired')
                    ->has('globalMetadataReviewNotRequired')
                    ->has('audioLoved')
                    ->has('audioLiked')
                    ->has('audioDisliked')
                    ->has('audioNoRating')
                    ->has('globalLoved')
                    ->has('globalLiked')
                    ->has('globalDisliked')
                    ->has('globalNoRating')
                    ->has('videoLoved')
                    ->has('videoLiked')
                    ->has('videoDisliked')
                    ->has('videoNoRating')
                    ->has('imageLoved')
                    ->has('imageLiked')
                    ->has('imageDisliked')
                    ->has('imageNoRating')
                    ->has('audioFiles')
                    ->has('videoFiles')
                    ->has('imageFiles')
                    ->has('otherFiles')
                    ->has('audioSize')
                    ->has('videoSize')
                    ->has('imageSize')
                    ->has('otherSize')
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
    $response->assertJsonStructure([
        'audioFilesCount',
        'audioSpaceUsed',
        'audioNotFound',
        'audioWithMetadata',
        'audioWithoutMetadata',
        'audioMetadataReviewRequired',
        'audioMetadataReviewNotRequired',
        'globalWithMetadata',
        'globalWithoutMetadata',
        'globalMetadataReviewRequired',
        'globalMetadataReviewNotRequired',
        'audioLoved',
        'audioLiked',
        'audioDisliked',
        'audioNoRating',
        'globalLoved',
        'globalLiked',
        'globalDisliked',
        'globalNoRating',
        'videoLoved',
        'videoLiked',
        'videoDisliked',
        'videoNoRating',
        'imageLoved',
        'imageLiked',
        'imageDisliked',
        'imageNoRating',
        'audioFiles',
        'videoFiles',
        'imageFiles',
        'otherFiles',
        'audioSize',
        'videoSize',
        'imageSize',
        'otherSize',
    ])
    ->assertJson([
        'audioFiles' => 2,
        'videoFiles' => 1,
        'imageFiles' => 1,
        'otherFiles' => 1,
        'audioNotFound' => 0,
        'audioWithoutMetadata' => 2,  // All test audio files don't have metadata
        'audioMetadataReviewRequired' => 0,
        // Note: Size, count, and rating values are dynamic based on test files
        // audioLoved, audioLiked, and audioDisliked values may vary based on factory data, so we don't assert them
    ]);
});

test('only admin can access users list', function () {
    // Create a regular user
    $regularUser = User::factory()->create([
        'is_admin' => false
    ]);

    // Create an admin user
    $admin = User::factory()->create([
        'is_admin' => true
    ]);

    // Regular user should be denied access
    $this->actingAs($regularUser)
        ->get('/users')
        ->assertStatus(403);

    // Admin should have access
    $this->actingAs($admin)
        ->get('/users')
        ->assertStatus(200);
});
