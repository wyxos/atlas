<?php

namespace Tests\Feature;

use App\Jobs\TranslateFileMetadata;
use App\Models\Cover;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TranslateFileMetadataTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Simple test cover art data
     */
    protected string $testCoverData;

    /**
     * MD5 hash of the test cover data
     */
    protected string $testCoverHash;

    public function setUp(): void
    {
        parent::setUp();

        // Create a fake storage disk for testing
        Storage::fake('public');

        // Create a simple test cover image
        $this->testCoverData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $this->testCoverHash = md5($this->testCoverData);
    }

    public function test_it_creates_cover_record_when_processing_metadata_with_cover_art()
    {
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
        $this->assertCount(1, $file->covers);

        // Assert that the metadata contains the cover path
        $this->assertNotNull($file->metadata->payload['cover_art_path']);
    }

    public function test_it_reuses_existing_cover_when_duplicate_is_found()
    {
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
        $this->assertEquals(1, Cover::count());

        // Assert that both files are associated with the same cover
        $this->assertCount(1, $file1->covers);
        $this->assertCount(1, $file2->covers);
        $this->assertEquals($existingCover->id, $file1->covers->first()->id);
        $this->assertEquals($existingCover->id, $file2->covers->first()->id);
    }
}
