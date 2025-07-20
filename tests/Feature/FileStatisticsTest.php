<?php

use App\Models\File;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    // Clear dashboard cache to avoid interference between tests
    Cache::forget('dashboard.file_stats');
    
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
                    // Video statistics
                    ->has('videoFilesCount')
                    ->has('videoSpaceUsed')
                    ->has('videoNotFound')
                    // Image statistics
                    ->has('imageFilesCount')
                    ->has('imageSpaceUsed')
                    ->has('imageNotFound')
                    // Total files not found
                    ->has('totalFilesNotFound')
                    // Audio metadata
                    ->has('audioWithMetadata')
                    ->has('audioWithoutMetadata')
                    ->has('audioMetadataReviewRequired')
                    ->has('audioMetadataReviewNotRequired')
                    // Global metadata
                    ->has('globalWithMetadata')
                    ->has('globalWithoutMetadata')
                    ->has('globalMetadataReviewRequired')
                    ->has('globalMetadataReviewNotRequired')
                    // Audio ratings
                    ->has('audioLoved')
                    ->has('audioLiked')
                    ->has('audioDisliked')
                    ->has('audioLaughedAt')
                    ->has('audioNoRating')
                    // Global ratings
                    ->has('globalLoved')
                    ->has('globalLiked')
                    ->has('globalDisliked')
                    ->has('globalLaughedAt')
                    ->has('globalNoRating')
                    // Video ratings
                    ->has('videoLoved')
                    ->has('videoLiked')
                    ->has('videoDisliked')
                    ->has('videoLaughedAt')
                    ->has('videoNoRating')
                    // Image ratings
                    ->has('imageLoved')
                    ->has('imageLiked')
                    ->has('imageDisliked')
                    ->has('imageLaughedAt')
                    ->has('imageNoRating')
                    // File type distribution
                    ->has('audioFiles')
                    ->has('videoFiles')
                    ->has('imageFiles')
                    ->has('otherFiles')
                    // File type sizes
                    ->has('audioSize')
                    ->has('videoSize')
                    ->has('imageSize')
                    ->has('otherSize')
                    // Disk space information
                    ->has('diskSpaceTotal')
                    ->has('diskSpaceUsed')
                    ->has('diskSpaceFree')
                    ->has('diskSpaceUsedPercent')
                    // Verify counts based on test data
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
        // Audio statistics
        'audioFilesCount',
        'audioSpaceUsed',
        'audioNotFound',
        // Video statistics
        'videoFilesCount',
        'videoSpaceUsed',
        'videoNotFound',
        // Image statistics
        'imageFilesCount',
        'imageSpaceUsed',
        'imageNotFound',
        // Total files not found
        'totalFilesNotFound',
        // Audio metadata
        'audioWithMetadata',
        'audioWithoutMetadata',
        'audioMetadataReviewRequired',
        'audioMetadataReviewNotRequired',
        // Global metadata
        'globalWithMetadata',
        'globalWithoutMetadata',
        'globalMetadataReviewRequired',
        'globalMetadataReviewNotRequired',
        // Audio ratings
        'audioLoved',
        'audioLiked',
        'audioDisliked',
        'audioLaughedAt',
        'audioNoRating',
        // Global ratings
        'globalLoved',
        'globalLiked',
        'globalDisliked',
        'globalLaughedAt',
        'globalNoRating',
        // Video ratings
        'videoLoved',
        'videoLiked',
        'videoDisliked',
        'videoLaughedAt',
        'videoNoRating',
        // Image ratings
        'imageLoved',
        'imageLiked',
        'imageDisliked',
        'imageLaughedAt',
        'imageNoRating',
        // File type distribution
        'audioFiles',
        'videoFiles',
        'imageFiles',
        'otherFiles',
        // File type sizes
        'audioSize',
        'videoSize',
        'imageSize',
        'otherSize',
        // Disk space information
        'diskSpaceTotal',
        'diskSpaceUsed',
        'diskSpaceFree',
        'diskSpaceUsedPercent',
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
