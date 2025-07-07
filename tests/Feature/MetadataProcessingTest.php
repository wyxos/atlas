<?php

use App\Jobs\ExtractFileMetadata;
use App\Jobs\TranslateFileMetadata;
use App\Models\File;
use App\Models\FileMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;



/**
 * Test subclass of ExtractFileMetadata that uses fixture metadata instead of Node.js script
 */
class TestExtractFileMetadataWithFixtures extends ExtractFileMetadata
{
    private string $fixtureFile;

    public function __construct(File $file, string $fixtureFile)
    {
        parent::__construct($file);
        $this->fixtureFile = $fixtureFile;
    }

    protected function executeMetadataScript(string $filePath): ?string
    {
        $fixturePath = base_path("tests/fixtures/{$this->fixtureFile}");

        if (! file_exists($fixturePath)) {
            return null;
        }

        return file_get_contents($fixturePath);
    }
}

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('atlas');
    Storage::fake('public');
});

describe('ExtractFileMetadata Job', function () {
    it('extracts complete metadata successfully', function () {
        $audioFile = File::factory()->create([
            'mime_type' => 'audio/mp3',
            'path' => base_path('tests/fixtures/audio/test_complete_metadata.mp3'),
            'filename' => 'test_complete_metadata.mp3',
        ]);

        $job = new TestExtractFileMetadataWithFixtures($audioFile, 'metadata_complete.json');
        $job->handle();

        // Assert metadata file was stored
        Storage::disk('atlas')->assertExists("metadata/{$audioFile->id}.json");

        // Assert metadata record was created
        $metadata = FileMetadata::where('file_id', $audioFile->id)->first();
        expect($metadata)->not->toBeNull();
        expect($metadata->is_extracted)->toBeTrue();

        // Verify the content matches our fixture
        $storedContent = Storage::disk('atlas')->get("metadata/{$audioFile->id}.json");
        $fixtureContent = file_get_contents(base_path('tests/fixtures/metadata_complete.json'));
        expect($storedContent)->toBe($fixtureContent);
    });

    it('extracts minimal metadata successfully', function () {
        $audioFile = File::factory()->create([
            'mime_type' => 'audio/mp3',
            'path' => base_path('tests/fixtures/audio/test_minimal_tags.mp3'),
            'filename' => 'test_minimal_tags.mp3',
        ]);

        $job = new TestExtractFileMetadataWithFixtures($audioFile, 'metadata_minimal.json');
        $job->handle();

        Storage::disk('atlas')->assertExists("metadata/{$audioFile->id}.json");

        $metadata = FileMetadata::where('file_id', $audioFile->id)->first();
        expect($metadata->is_extracted)->toBeTrue();
    });

    it('handles extraction failure gracefully', function () {
        $audioFile = File::factory()->create([
            'mime_type' => 'audio/mp3',
            'path' => '/nonexistent/path/audio.mp3',
            'filename' => 'nonexistent.mp3',
        ]);

        $job = new TestExtractFileMetadataWithFixtures($audioFile, 'nonexistent_fixture.json');
        $job->handle();

        // Should not create metadata file
        Storage::disk('local')->assertMissing("metadata/{$audioFile->id}.json");

        // Should not create metadata record
        expect(FileMetadata::where('file_id', $audioFile->id)->exists())->toBeFalse();
    });
});

describe('TranslateFileMetadata Job', function () {
    it('translates complete metadata correctly', function () {
        $audioFile = File::factory()->create([
            'mime_type' => 'audio/mp3',
            'path' => base_path('tests/fixtures/audio/test_complete_metadata.mp3'),
            'filename' => 'test_complete_metadata.mp3',
            'tags' => [],
        ]);

        // Create metadata record
        $audioFile->metadata()->create(['is_extracted' => true]);

        // Store the metadata JSON file
        $metadataContent = file_get_contents(base_path('tests/fixtures/metadata_complete.json'));
        Storage::disk('atlas')->put("metadata/{$audioFile->id}.json", $metadataContent);

        $job = new TranslateFileMetadata($audioFile);
        $job->handle();

        // Refresh the metadata record
        $metadata = $audioFile->metadata()->first();

        expect($metadata->is_review_required)->toBeFalse();
        expect($metadata->payload)->toBeArray();

        // Check that title was extracted
        expect($metadata->payload['title'])->toBe('Canon in D Major');

        // Check that codec information was extracted
        expect($metadata->payload['codec'])->toBe('MP3');
        expect($metadata->payload['bitrate'])->toBe(128000);
        expect($metadata->payload['duration'])->toBe(30.123);

        // Check that tags were populated (artist and album are no longer stored in payload)
        // Instead, check that the artist and album relationships were created
        expect($audioFile->artists()->count())->toBe(1);
        expect($audioFile->artists()->first()->name)->toBe('Johann Pachelbel');
        expect($audioFile->albums()->count())->toBe(1);
        expect($audioFile->albums()->first()->name)->toBe('Classical Music for Relaxation');
        expect($metadata->payload['year'])->toBe('1680');
        expect($metadata->payload['track'])->toBe('1/12');
    });

    it('handles cover art extraction', function () {
        $audioFile = File::factory()->create([
            'mime_type' => 'audio/mp3',
            'path' => base_path('tests/fixtures/audio/test_complete_metadata.mp3'),
            'filename' => 'test_complete_metadata.mp3',
        ]);

        $audioFile->metadata()->create(['is_extracted' => true]);

        $metadataContent = file_get_contents(base_path('tests/fixtures/metadata_complete.json'));
        Storage::disk('atlas')->put("metadata/{$audioFile->id}.json", $metadataContent);

        $job = new TranslateFileMetadata($audioFile);
        $job->handle();

        $metadata = $audioFile->metadata()->first();

        // Verify that a cover record was created and associated with the album (prioritized over artist)
        expect($audioFile->artists()->count())->toBe(1);
        expect($audioFile->albums()->count())->toBe(1);
        $album = $audioFile->albums->first();
        expect($album->covers()->count())->toBe(1);

        // Get the cover
        $cover = $album->covers->first();

        // Verify the cover exists in storage
        Storage::disk('atlas')->assertExists($cover->path);
    });

    it('translates minimal metadata correctly', function () {
        $audioFile = File::factory()->create([
            'mime_type' => 'audio/mp3',
            'path' => base_path('tests/fixtures/audio/test_minimal_tags.mp3'),
            'filename' => 'test_minimal_tags.mp3',
            'tags' => ['existing_tag' => 'value'],
        ]);

        $audioFile->metadata()->create(['is_extracted' => true]);

        $metadataContent = file_get_contents(base_path('tests/fixtures/metadata_minimal.json'));
        Storage::disk('atlas')->put("metadata/{$audioFile->id}.json", $metadataContent);

        $job = new TranslateFileMetadata($audioFile);
        $job->handle();

        $metadata = $audioFile->metadata()->first();

        expect($metadata->is_review_required)->toBeFalse();
        expect($metadata->payload['title'])->toBe('Test Audio File');
        expect($metadata->payload['existing_tag'])->toBe('value'); // Should preserve existing tags

        // Check that the artist relationship was created
        expect($audioFile->artists()->count())->toBe(1);
        expect($audioFile->artists()->first()->name)->toBe('Test Artist');
    });

    it('handles ID3v1 metadata correctly', function () {
        $audioFile = File::factory()->create([
            'mime_type' => 'audio/mp3',
            'path' => base_path('tests/fixtures/audio/test_jazz_sample.mp3'),
            'filename' => 'test_jazz_sample.mp3',
        ]);

        $audioFile->metadata()->create(['is_extracted' => true]);

        $metadataContent = file_get_contents(base_path('tests/fixtures/metadata_id3v1.json'));
        Storage::disk('atlas')->put("metadata/{$audioFile->id}.json", $metadataContent);

        $job = new TranslateFileMetadata($audioFile);
        $job->handle();

        $metadata = $audioFile->metadata()->first();

        expect($metadata->is_review_required)->toBeFalse();
        expect($metadata->payload['title'])->toBe('Old Format Song');
        expect($metadata->payload['year'])->toBe('1995');
        expect($metadata->payload['track'])->toBe('5');

        // Check that the artist and album relationships were created
        expect($audioFile->artists()->count())->toBe(1);
        expect($audioFile->artists()->first()->name)->toBe('Vintage Artist');
        expect($audioFile->albums()->count())->toBe(1);
        expect($audioFile->albums()->first()->name)->toBe('Classic Album');
    });

    it('marks files with no metadata for review', function () {
        $audioFile = File::factory()->create([
            'mime_type' => 'audio/mp3',
            'path' => base_path('tests/fixtures/audio/test_short_sample.mp3'),
            'filename' => 'test_short_sample.mp3',
        ]);

        $audioFile->metadata()->create(['is_extracted' => true]);

        $metadataContent = file_get_contents(base_path('tests/fixtures/metadata_no_tags.json'));
        Storage::disk('atlas')->put("metadata/{$audioFile->id}.json", $metadataContent);

        $job = new TranslateFileMetadata($audioFile);
        $job->handle();

        $metadata = $audioFile->metadata()->first();

        // Should be marked for review since no title, artist, or album found
        expect($metadata->is_review_required)->toBeTrue();

        // Should still extract format information
        expect($metadata->payload['codec'])->toBe('MP3');
        expect($metadata->payload['bitrate'])->toBe(320000);

        // Should use filename as title when no title found
        expect($metadata->payload['title'])->toBe('test_short_sample.mp3');
    });

    it('handles corrupted metadata JSON gracefully', function () {
        $audioFile = File::factory()->create([
            'mime_type' => 'audio/mp3',
            'path' => base_path('tests/fixtures/audio/test_minimal_tags.mp3'),
            'filename' => 'test_minimal_tags.mp3',
        ]);

        $audioFile->metadata()->create(['is_extracted' => true]);

        // Store invalid JSON
        Storage::disk('atlas')->put("metadata/{$audioFile->id}.json", '{ invalid json content');

        $job = new TranslateFileMetadata($audioFile);
        $job->handle();

        $metadata = $audioFile->metadata()->first();

        // Should be marked for review due to JSON parsing error
        expect($metadata->is_review_required)->toBeTrue();
    });

    it('handles missing metadata file gracefully', function () {
        $audioFile = File::factory()->create([
            'mime_type' => 'audio/mp3',
            'path' => base_path('tests/fixtures/audio/test_minimal_tags.mp3'),
            'filename' => 'test_minimal_tags.mp3',
        ]);

        $audioFile->metadata()->create(['is_extracted' => true]);

        // Don't store any metadata file

        $job = new TranslateFileMetadata($audioFile);
        $job->handle();

        // Metadata record should remain unchanged
        $metadata = $audioFile->metadata()->first();
        expect($metadata->payload)->toBeNull();
    });

    it('preserves existing file tags when merging', function () {
        $audioFile = File::factory()->create([
            'mime_type' => 'audio/mp3',
            'path' => base_path('tests/fixtures/audio/test_complete_metadata.mp3'),
            'filename' => 'test_complete_metadata.mp3',
            'tags' => [
                'user_rating' => 5,
                'custom_tag' => 'custom_value',
                'artist' => 'Original Artist', // This should be overwritten
            ],
        ]);

        $audioFile->metadata()->create(['is_extracted' => true]);

        $metadataContent = file_get_contents(base_path('tests/fixtures/metadata_complete.json'));
        Storage::disk('atlas')->put("metadata/{$audioFile->id}.json", $metadataContent);

        $job = new TranslateFileMetadata($audioFile);
        $job->handle();

        $metadata = $audioFile->metadata()->first();

        // Custom tags should be preserved
        expect($metadata->payload['user_rating'])->toBe(5);
        expect($metadata->payload['custom_tag'])->toBe('custom_value');

        // Artist relationship should be created from metadata, not from file tags
        expect($audioFile->artists()->count())->toBe(1);
        expect($audioFile->artists()->first()->name)->toBe('Johann Pachelbel');
    });
});

describe('Integration Tests', function () {
    it('processes complete workflow from extraction to translation', function () {
        $audioFile = File::factory()->create([
            'mime_type' => 'audio/mp3',
            'path' => base_path('tests/fixtures/audio/test_complete_metadata.mp3'),
            'filename' => 'test_complete_metadata.mp3',
        ]);

        // Step 1: Extract metadata
        $extractJob = new TestExtractFileMetadataWithFixtures($audioFile, 'metadata_complete.json');
        $extractJob->handle();

        // Verify extraction worked
        Storage::disk('atlas')->assertExists("metadata/{$audioFile->id}.json");

        $metadata = FileMetadata::where('file_id', $audioFile->id)->first();
        expect($metadata->is_extracted)->toBeTrue();

        // Step 2: Translate metadata
        $translateJob = new TranslateFileMetadata($audioFile);
        $translateJob->handle();

        // Refresh metadata
        $metadata->refresh();

        // Verify translation worked
        expect($metadata->payload)->toBeArray();
        expect($metadata->payload['title'])->toBe('Canon in D Major');
        expect($metadata->is_review_required)->toBeFalse();

        // Verify that a cover record was created and associated with the album (prioritized over artist)
        expect($audioFile->artists()->count())->toBe(1);
        expect($audioFile->albums()->count())->toBe(1);
        $album = $audioFile->albums->first();
        expect($album->covers()->count())->toBe(1);

        // Get the cover
        $cover = $album->covers->first();

        // Verify the cover exists in storage
        Storage::disk('atlas')->assertExists($cover->path);
    });

    it('handles files that require review in complete workflow', function () {
        $audioFile = File::factory()->create([
            'mime_type' => 'audio/mp3',
            'path' => base_path('tests/fixtures/audio/test_short_sample.mp3'),
            'filename' => 'test_short_sample.mp3',
        ]);

        // Extract and translate
        $extractJob = new TestExtractFileMetadataWithFixtures($audioFile, 'metadata_no_tags.json');
        $extractJob->handle();

        $translateJob = new TranslateFileMetadata($audioFile);
        $translateJob->handle();

        $metadata = FileMetadata::where('file_id', $audioFile->id)->first();

        // Should be marked for review due to lack of basic metadata
        expect($metadata->is_review_required)->toBeTrue();
        expect($metadata->payload['title'])->toBe('test_short_sample.mp3'); // Uses filename as fallback
    });
});
