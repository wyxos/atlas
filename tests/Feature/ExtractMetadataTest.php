<?php

use App\Console\Commands\ExtractMetadata;
use App\Jobs\ExtractFileMetadata;
use App\Models\File;
use App\Models\FileMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

/**
 * Test subclass of ExtractFileMetadata that overrides the executeMetadataScript method
 */
class TestExtractFileMetadata extends ExtractFileMetadata
{
    private ?string $testOutput;

    public function __construct(File $file, ?string $testOutput)
    {
        parent::__construct($file);
        $this->testOutput = $testOutput;
    }

    protected function executeMetadataScript(string $filePath): ?string
    {
        return $this->testOutput;
    }
}

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create a fake storage disk for metadata
    Storage::fake('local');
    Storage::fake('atlas');
});

it('dispatches jobs for audio files when command is run', function () {
    // Fake the bus to catch dispatched jobs
    Bus::fake();

    // Create some audio files
    $audioFile1 = File::factory()->create(['mime_type' => 'audio/mp3']);
    $audioFile2 = File::factory()->create(['mime_type' => 'audio/wav']);

    // Create a non-audio file (should be ignored)
    $nonAudioFile = File::factory()->create(['mime_type' => 'video/mp4']);

    // Run the command
    $this->artisan(ExtractMetadata::class)
        ->expectsOutput("Queuing metadata extraction for file: {$audioFile1->path}")
        ->expectsOutput("Queuing metadata extraction for file: {$audioFile2->path}")
        ->expectsOutput('Queued metadata extraction for 2 files.')
        ->assertSuccessful();

    // Assert that jobs were dispatched for audio files only
    Bus::assertDispatched(ExtractFileMetadata::class, function ($job) use ($audioFile1) {
        return $job->getFile()->id === $audioFile1->id;
    });

    Bus::assertDispatched(ExtractFileMetadata::class, function ($job) use ($audioFile2) {
        return $job->getFile()->id === $audioFile2->id;
    });

    Bus::assertNotDispatched(ExtractFileMetadata::class, function ($job) use ($nonAudioFile) {
        return $job->getFile()->id === $nonAudioFile->id;
    });
});

it('dispatches job only for the specified file when using --file option', function () {
    // Fake the bus to catch dispatched jobs
    Bus::fake();

    // Create some audio files
    $audioFile1 = File::factory()->create(['mime_type' => 'audio/mp3']);
    $audioFile2 = File::factory()->create(['mime_type' => 'audio/wav']);

    // Run the command with the --file option for audioFile1
    $this->artisan(ExtractMetadata::class, ['--file' => $audioFile1->id])
        ->expectsOutput("Processing only file with ID: {$audioFile1->id}")
        ->expectsOutput("Queuing metadata extraction for file: {$audioFile1->path}")
        ->expectsOutput('Queued metadata extraction for 1 files.')
        ->assertSuccessful();

    // Assert that a job was dispatched only for the specified file
    Bus::assertDispatched(ExtractFileMetadata::class, function ($job) use ($audioFile1) {
        return $job->getFile()->id === $audioFile1->id;
    });

    // Assert that no job was dispatched for the other file
    Bus::assertNotDispatched(ExtractFileMetadata::class, function ($job) use ($audioFile2) {
        return $job->getFile()->id === $audioFile2->id;
    });
});

it('processes file and updates metadata when job is executed', function () {
    // Create an audio file
    $audioFile = File::factory()->create(['mime_type' => 'audio/mp3', 'path' => '/path/to/audio.mp3']);

    // Create a test job with successful metadata extraction
    $job = new TestExtractFileMetadata($audioFile, json_encode(['title' => 'Test Song', 'artist' => 'Test Artist']));
    $job->handle();

    // Assert that metadata file was stored
    Storage::disk('atlas')->assertExists("metadata/{$audioFile->id}.json");

    // Assert that metadata record was created with is_extracted = true
    expect(FileMetadata::where('file_id', $audioFile->id)->where('is_extracted', true)->exists())->toBeTrue();
});

it('handles extraction failure gracefully', function () {
    // Create an audio file
    $audioFile = File::factory()->create(['mime_type' => 'audio/mp3', 'path' => '/path/to/audio.mp3']);

    // Create a test job with failed metadata extraction
    $job = new TestExtractFileMetadata($audioFile, null);
    $job->handle();

    // Assert that metadata file was not stored
    Storage::disk('local')->assertMissing("metadata/{$audioFile->id}.json");

    // Assert that metadata record was not created
    expect(FileMetadata::where('file_id', $audioFile->id)->exists())->toBeFalse();
});
