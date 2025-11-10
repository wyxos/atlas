<?php

use App\Models\File;
use App\Models\FileMetadata;
use App\Models\Reaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('dashboard returns inertia component with fileStats', function () {
    $user = User::factory()->create();

    // Seed a small set of files across types
    $audio1 = File::factory()->create(['mime_type' => 'audio/mpeg', 'size' => 1024, 'not_found' => false]);
    $audio2 = File::factory()->create(['mime_type' => 'audio/flac', 'size' => 2048, 'not_found' => true]);

    $video = File::factory()->create(['mime_type' => 'video/mp4', 'size' => 4096, 'not_found' => false]);

    $image1 = File::factory()->create(['mime_type' => 'image/jpeg', 'size' => 512, 'not_found' => false]);
    $image2 = File::factory()->create(['mime_type' => 'image/png', 'size' => 1024, 'not_found' => false]);

    // Metadata
    FileMetadata::factory()->create([
        'file_id' => $audio1->id,
        'is_review_required' => true,
    ]);

    // Reactions (one per file for the same user is sufficient for counts)
    Reaction::create(['file_id' => $audio1->id, 'user_id' => $user->id, 'type' => 'love']);
    Reaction::create(['file_id' => $video->id,   'user_id' => $user->id, 'type' => 'like']);
    Reaction::create(['file_id' => $image1->id,  'user_id' => $user->id, 'type' => 'dislike']);
    Reaction::create(['file_id' => $image2->id,  'user_id' => $user->id, 'type' => 'funny']);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('fileStats')
            ->where('fileStats.audioFilesCount', 2)
            ->where('fileStats.videoFilesCount', 1)
            ->where('fileStats.imageFilesCount', 2)
            ->where('fileStats.totalFilesNotFound', 1)
        );
});

test('dashboard stats json returns expected keys', function () {
    $user = User::factory()->create();

    // Minimal seed to exercise endpoint
    File::factory()->create(['mime_type' => 'audio/mpeg', 'size' => 100]);
    File::factory()->create(['mime_type' => 'video/mp4', 'size' => 200]);
    File::factory()->create(['mime_type' => 'image/jpeg', 'size' => 300, 'not_found' => true]);

    $res = $this->actingAs($user)->get(route('dashboard.stats'));

    $res->assertSuccessful()
        ->assertJsonStructure([
            'audioFilesCount',
            'videoFilesCount',
            'imageFilesCount',
            'totalFilesNotFound',
            'diskSpaceTotal',
            'diskSpaceUsed',
            'diskSpaceFree',
            'diskSpaceUsedPercent',
        ]);
});
