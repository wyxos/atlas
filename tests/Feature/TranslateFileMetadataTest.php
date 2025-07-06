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
    Storage::fake('atlas');

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
                    'value' => 'Test Title',
                ],
                [
                    'id' => 'TPE1',
                    'value' => 'Test Artist',
                ],
                [
                    'id' => 'APIC',
                    'value' => [
                        'format' => 'image/png',
                        'data' => array_values(unpack('C*', $this->testCoverData)),
                    ],
                ],
            ],
        ],
        'format' => [
            'duration' => 180,
            'bitrate' => 320000,
        ],
    ];

    // Save the metadata
    $metadataPath = "metadata/{$file->id}.json";
    Storage::disk('atlas')->put($metadataPath, json_encode($metadata));

    // Process the file
    $job = new TranslateFileMetadata($file);
    $job->handle();

    // Assert that a cover record was created
    $this->assertDatabaseHas('covers', [
        'hash' => $this->testCoverHash,
    ]);

    // Assert that the cover is associated with the artist
    expect($file->artists)->toHaveCount(1);
    $artist = $file->artists->first();
    expect($artist->covers)->toHaveCount(1);

    // We no longer store cover_art_path in the metadata payload
});

test('it reuses existing cover when duplicate is found', function () {
    // Create a test artist first to associate with the cover
    $artist = \App\Models\Artist::create(['name' => 'Test Artist']);

    // Create a test cover using our test cover data
    $existingCover = Cover::create([
        'hash' => $this->testCoverHash,
        'path' => 'covers/existing-cover.png',
        'coverable_id' => $artist->id,
        'coverable_type' => \App\Models\Artist::class,
    ]);

    Storage::disk('atlas')->put($existingCover->path, $this->testCoverData);

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
                    'value' => 'Test Title',
                ],
                [
                    'id' => 'TPE1',
                    'value' => 'Test Artist',
                ],
            ],
        ],
        'format' => [
            'duration' => 180,
            'bitrate' => 320000,
        ],
    ];

    // Add the cover art to the metadata
    $coverArtTag = [
        'id' => 'APIC',
        'value' => [
            'format' => 'image/png',
            'data' => array_values(unpack('C*', $this->testCoverData)),
        ],
    ];

    // Create metadata for both files
    $metadata1 = $baseMetadata;
    $metadata2 = $baseMetadata;

    // Add the cover art to the metadata
    $metadata1['native']['ID3v2.3'][] = $coverArtTag;
    $metadata2['native']['ID3v2.3'][] = $coverArtTag;

    Storage::disk('atlas')->put("metadata/{$file1->id}.json", json_encode($metadata1));
    Storage::disk('atlas')->put("metadata/{$file2->id}.json", json_encode($metadata2));

    // Process the first file
    $job1 = new TranslateFileMetadata($file1);
    $job1->handle();

    // Process the second file
    $job2 = new TranslateFileMetadata($file2);
    $job2->handle();

    // Assert that only one cover record exists
    expect(Cover::count())->toBe(1);

    // Assert that both files have artists and the artists are associated with the same cover
    expect($file1->artists)->toHaveCount(1);
    expect($file2->artists)->toHaveCount(1);

    $artist1 = $file1->artists->first();
    $artist2 = $file2->artists->first();

    // Both should be the same artist since they have the same name
    expect($artist1->id)->toBe($artist2->id);
    expect($artist1->covers)->toHaveCount(1);
    expect($artist1->covers->first()->id)->toBe($existingCover->id);
});

test('it processes PIC tag for cover art', function () {
    // Create a test file
    $file = File::factory()->create([
        'mime_type' => 'audio/mp3',
    ]);

    // Create a simple metadata structure with PIC tag for cover art
    $metadata = [
        'native' => [
            'ID3v2.3' => [
                [
                    'id' => 'TIT2',
                    'value' => 'Test Title',
                ],
                [
                    'id' => 'TPE1',
                    'value' => 'Test Artist',
                ],
                [
                    'id' => 'PIC', // Using PIC instead of APIC
                    'value' => [
                        'format' => 'image/png',
                        'data' => array_values(unpack('C*', $this->testCoverData)),
                    ],
                ],
            ],
        ],
        'format' => [
            'duration' => 180,
            'bitrate' => 320000,
        ],
    ];

    // Save the metadata
    $metadataPath = "metadata/{$file->id}.json";
    Storage::disk('atlas')->put($metadataPath, json_encode($metadata));

    // Process the file
    $job = new TranslateFileMetadata($file);
    $job->handle();

    // Assert that a cover record was created
    $this->assertDatabaseHas('covers', [
        'hash' => $this->testCoverHash,
    ]);

    // Assert that the cover is associated with the artist
    expect($file->artists)->toHaveCount(1);
    $artist = $file->artists->first();
    expect($artist->covers)->toHaveCount(1);

    // Get the cover
    $cover = $artist->covers->first();

    // Assert that the cover file exists in storage
    Storage::disk('atlas')->assertExists($cover->path);
});

test('it prioritizes album over artist when associating covers', function () {
    // Create a test file
    $file = File::factory()->create([
        'mime_type' => 'audio/mp3',
    ]);

    // Create a simple metadata structure with both artist and album, plus cover art
    $metadata = [
        'native' => [
            'ID3v2.3' => [
                [
                    'id' => 'TIT2',
                    'value' => 'Test Title',
                ],
                [
                    'id' => 'TPE1',
                    'value' => 'Test Artist',
                ],
                [
                    'id' => 'TALB',
                    'value' => 'Test Album',
                ],
                [
                    'id' => 'APIC',
                    'value' => [
                        'format' => 'image/png',
                        'data' => array_values(unpack('C*', $this->testCoverData)),
                    ],
                ],
            ],
        ],
        'format' => [
            'duration' => 180,
            'bitrate' => 320000,
        ],
    ];

    // Save the metadata
    $metadataPath = "metadata/{$file->id}.json";
    Storage::disk('atlas')->put($metadataPath, json_encode($metadata));

    // Process the file
    $job = new TranslateFileMetadata($file);
    $job->handle();

    // Assert that a cover record was created
    $this->assertDatabaseHas('covers', [
        'hash' => $this->testCoverHash,
    ]);

    // Assert that both artist and album were created
    expect($file->artists)->toHaveCount(1);
    expect($file->albums)->toHaveCount(1);

    $artist = $file->artists->first();
    $album = $file->albums->first();

    // Assert that the cover is associated with the album, not the artist
    expect($album->covers)->toHaveCount(1);
    expect($artist->covers)->toHaveCount(0);

    // Verify the cover is correctly associated with the album
    $cover = $album->covers->first();
    expect($cover->coverable_type)->toBe(\App\Models\Album::class);
    expect($cover->coverable_id)->toBe($album->id);
});

test('it associates cover with artist when no album is present', function () {
    // Create a test file
    $file = File::factory()->create([
        'mime_type' => 'audio/mp3',
    ]);

    // Create a simple metadata structure with only artist (no album), plus cover art
    $metadata = [
        'native' => [
            'ID3v2.3' => [
                [
                    'id' => 'TIT2',
                    'value' => 'Test Title',
                ],
                [
                    'id' => 'TPE1',
                    'value' => 'Test Artist',
                ],
                [
                    'id' => 'APIC',
                    'value' => [
                        'format' => 'image/png',
                        'data' => array_values(unpack('C*', $this->testCoverData)),
                    ],
                ],
            ],
        ],
        'format' => [
            'duration' => 180,
            'bitrate' => 320000,
        ],
    ];

    // Save the metadata
    $metadataPath = "metadata/{$file->id}.json";
    Storage::disk('atlas')->put($metadataPath, json_encode($metadata));

    // Process the file
    $job = new TranslateFileMetadata($file);
    $job->handle();

    // Assert that a cover record was created
    $this->assertDatabaseHas('covers', [
        'hash' => $this->testCoverHash,
    ]);

    // Assert that only artist was created (no album)
    expect($file->artists)->toHaveCount(1);
    expect($file->albums)->toHaveCount(0);

    $artist = $file->artists->first();

    // Assert that the cover is associated with the artist
    expect($artist->covers)->toHaveCount(1);

    // Verify the cover is correctly associated with the artist
    $cover = $artist->covers->first();
    expect($cover->coverable_type)->toBe(\App\Models\Artist::class);
    expect($cover->coverable_id)->toBe($artist->id);
});
