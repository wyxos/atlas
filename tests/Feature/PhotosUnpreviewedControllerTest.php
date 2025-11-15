<?php

use App\Models\File;
use App\Models\User;
use Laravel\Scout\EngineManager;

beforeEach(function () {
    $this->originalScoutDriver = config('scout.driver');

    $this->fakeTypesense = new FakeTypesenseEngine;

    resolve(EngineManager::class)->extend('fake-typesense', function () {
        return $this->fakeTypesense;
    });

    config()->set('scout.driver', 'fake-typesense');
});

afterEach(function () {
    config()->set('scout.driver', $this->originalScoutDriver);
});

it('returns only image files with correct mime_group in Scout index', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Create an image file (should be included)
    $imageFile = File::factory()->create([
        'mime_type' => 'image/jpeg',
        'path' => 'photos/example.jpg',
        'previewed_count' => 0,
        'blacklisted_at' => null,
        'not_found' => false,
    ]);

    // Create an audio file (MP3) - should be excluded because mime_group is 'audio'
    $audioFile = File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'path' => '0000 - Downloads/3_Koshico_Records_-_ESTA-_-_Hard_Drain_sweet.mp3',
        'filename' => '3_Koshico_Records_-_ESTA-_-_Hard_Drain_sweet',
        'ext' => 'mp3',
        'previewed_count' => 0,
        'blacklisted_at' => null,
        'not_found' => false,
    ]);

    // Scout documents with correct mime_group values (as calculated by toSearchableArray)
    $documents = [
        [
            'id' => (string) $imageFile->id,
            'mime_group' => 'image', // Correct: matches mime_type 'image/jpeg'
            'mime_type' => 'image/jpeg',
            'not_found' => false,
            'blacklisted' => false,
            'previewed_count' => 0,
            'reacted_user_ids' => [],
            'created_at' => $imageFile->created_at?->timestamp ?? 0,
        ],
        [
            'id' => (string) $audioFile->id,
            'mime_group' => 'audio', // Correct: matches mime_type 'audio/mpeg'
            'mime_type' => 'audio/mpeg',
            'not_found' => false,
            'blacklisted' => false,
            'previewed_count' => 0,
            'reacted_user_ids' => [],
            'created_at' => $audioFile->created_at?->timestamp ?? 0,
        ],
    ];

    $this->fakeTypesense->setDocuments($documents);

    $response = $this->getJson(route('photos.unpreviewed.data', [
        'sort' => 'newest',
        'limit' => 20,
    ]));

    $response->assertOk();

    $payload = $response->json();
    $filesPayload = $payload['files'];

    // Should only return the image file, not the audio file (filtered by Scout's mime_group='image')
    expect($filesPayload)->toHaveCount(1);
    expect($filesPayload[0]['id'])->toBe($imageFile->id);

    // Verify the audio file is NOT in the results
    $returnedIds = collect($filesPayload)->pluck('id')->all();
    expect($returnedIds)->not->toContain($audioFile->id);
});

it('returns only image files with previewed_count=0', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Create image files
    $image1 = File::factory()->create([
        'mime_type' => 'image/jpeg',
        'previewed_count' => 0,
        'blacklisted_at' => null,
        'not_found' => false,
    ]);

    $image2 = File::factory()->create([
        'mime_type' => 'image/png',
        'previewed_count' => 0,
        'blacklisted_at' => null,
        'not_found' => false,
    ]);

    // Create an image that's already been previewed (should be excluded)
    $previewedImage = File::factory()->create([
        'mime_type' => 'image/jpeg',
        'previewed_count' => 1,
        'blacklisted_at' => null,
        'not_found' => false,
    ]);

    $documents = [
        [
            'id' => (string) $image1->id,
            'mime_group' => 'image',
            'mime_type' => 'image/jpeg',
            'not_found' => false,
            'blacklisted' => false,
            'previewed_count' => 0,
            'reacted_user_ids' => [],
            'created_at' => $image1->created_at?->timestamp ?? 0,
        ],
        [
            'id' => (string) $image2->id,
            'mime_group' => 'image',
            'mime_type' => 'image/png',
            'not_found' => false,
            'blacklisted' => false,
            'previewed_count' => 0,
            'reacted_user_ids' => [],
            'created_at' => $image2->created_at?->timestamp ?? 0,
        ],
        [
            'id' => (string) $previewedImage->id,
            'mime_group' => 'image',
            'mime_type' => 'image/jpeg',
            'not_found' => false,
            'blacklisted' => false,
            'previewed_count' => 1, // Already previewed
            'reacted_user_ids' => [],
            'created_at' => $previewedImage->created_at?->timestamp ?? 0,
        ],
    ];

    $this->fakeTypesense->setDocuments($documents);

    $response = $this->getJson(route('photos.unpreviewed.data', [
        'sort' => 'newest',
        'limit' => 20,
    ]));

    $response->assertOk();

    $payload = $response->json();
    $filesPayload = $payload['files'];

    // Should only return unpreviewed images
    expect($filesPayload)->toHaveCount(2);
    $returnedIds = collect($filesPayload)->pluck('id')->all();
    expect($returnedIds)->toContain($image1->id);
    expect($returnedIds)->toContain($image2->id);
    expect($returnedIds)->not->toContain($previewedImage->id);
});
