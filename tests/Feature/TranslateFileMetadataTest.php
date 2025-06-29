<?php

namespace Tests\Feature;

use App\Jobs\TranslateFileMetadata;
use App\Models\Cover;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create a fake storage disk for testing
    Storage::fake('public');

    // Create a simple test cover image
    $this->testCoverData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $this->testCoverHash = md5($this->testCoverData);
});

test('it creates cover record when processing metadata with cover art', function () {
    // Create a test file
    $file = File::factory()->create([
        'mime_type' => 'audio/mp3',
    ]);

    // Create a simple metadata structure with cover art
    $metadata = [
        'native' => [
            'ID3v2.3' => [
                [
                    'id' => 'TIT2',
                    'value' => 'Test Title'
                ],
                [
                    'id' => 'TPE1',
                    'value' => 'Test Artist'
                ],
                [
                    'id' => 'APIC',
                    'value' => [
                        'format' => 'image/png',
                        'data' => array_values(unpack('C*', $this->testCoverData))
                    ]
                ]
            ]
        ],
        'format' => [
            'duration' => 180,
            'bitrate' => 320000
        ]
    ];

    // Save the metadata
    $metadataPath = "metadata/{$file->id}.json";
    Storage::put($metadataPath, json_encode($metadata));

    // Process the file
    $job = new TranslateFileMetadata($file);
    $job->handle();

    // Assert that a cover record was created
    $this->assertDatabaseHas('covers', [
        'hash' => $this->testCoverHash
    ]);

    // Assert that the cover is associated with the file
    expect($file->covers)->toHaveCount(1);

    // Assert that the metadata contains the cover path
    expect($file->metadata->payload['cover_art_path'])->not->toBeNull();
});

test('it reuses existing cover when duplicate is found', function () {
    // Create a test cover using our test cover data
    $existingCover = Cover::create([
        'hash' => $this->testCoverHash,
        'path' => 'covers/existing-cover.png'
    ]);

    Storage::disk('public')->put($existingCover->path, $this->testCoverData);

    // Create two test files
    $file1 = File::factory()->create([
        'mime_type' => 'audio/mp3',
    ]);

    $file2 = File::factory()->create([
        'mime_type' => 'audio/mp3',
    ]);

    // Create a simple metadata structure with cover art
    $baseMetadata = [
        'native' => [
            'ID3v2.3' => [
                [
                    'id' => 'TIT2',
                    'value' => 'Test Title'
                ],
                [
                    'id' => 'TPE1',
                    'value' => 'Test Artist'
                ]
            ]
        ],
        'format' => [
            'duration' => 180,
            'bitrate' => 320000
        ]
    ];

    // Add the cover art to the metadata
    $coverArtTag = [
        'id' => 'APIC',
        'value' => [
            'format' => 'image/png',
            'data' => array_values(unpack('C*', $this->testCoverData))
        ]
    ];

    // Create metadata for both files
    $metadata1 = $baseMetadata;
    $metadata2 = $baseMetadata;

    // Add the cover art to the metadata
    $metadata1['native']['ID3v2.3'][] = $coverArtTag;
    $metadata2['native']['ID3v2.3'][] = $coverArtTag;

    Storage::put("metadata/{$file1->id}.json", json_encode($metadata1));
    Storage::put("metadata/{$file2->id}.json", json_encode($metadata2));

    // Process the first file
    $job1 = new TranslateFileMetadata($file1);
    $job1->handle();

    // Process the second file
    $job2 = new TranslateFileMetadata($file2);
    $job2->handle();

    // Assert that only one cover record exists
    expect(Cover::count())->toBe(1);

    // Assert that both files are associated with the same cover
    expect($file1->covers)->toHaveCount(1);
    expect($file2->covers)->toHaveCount(1);
    expect($file1->covers->first()->id)->toBe($existingCover->id);
    expect($file2->covers->first()->id)->toBe($existingCover->id);
});
