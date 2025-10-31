<?php

namespace Tests\Feature\Commands;

use App\Jobs\FixDoubleEncodedListingMetadataJob;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FixDoubleEncodedListingMetadataTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_fixes_double_encoded_json_metadata()
    {
        // Arrange: Create a file with double-encoded JSON
        $originalData = [
            'postId' => 12345,
            'username' => 'test_user',
        ];

        $doubleEncoded = json_encode(json_encode($originalData));

        $file = File::factory()->create([
            'source' => 'CivitAI',
        ]);

        // Manually set double-encoded JSON in database
        \DB::table('files')
            ->where('id', $file->id)
            ->update(['listing_metadata' => $doubleEncoded]);

        // Act: Execute the job
        $job = new FixDoubleEncodedListingMetadataJob($file->id);
        $job->handle();

        // Assert: Metadata is now properly decoded
        $file->refresh();
        $this->assertIsArray($file->listing_metadata);
        $this->assertEquals(12345, $file->listing_metadata['postId']);
        $this->assertEquals('test_user', $file->listing_metadata['username']);
    }

    /** @test */
    public function it_does_not_modify_already_valid_metadata()
    {
        // Arrange: Create file with valid metadata
        $validData = [
            'postId' => 99999,
            'username' => 'valid_user',
        ];

        $file = File::factory()->create([
            'source' => 'CivitAI',
            'listing_metadata' => $validData,
        ]);

        // Act: Execute the job
        $job = new FixDoubleEncodedListingMetadataJob($file->id);
        $job->handle();

        // Assert: Metadata unchanged
        $file->refresh();
        $this->assertEquals($validData, $file->listing_metadata);
    }

    /** @test */
    public function it_handles_null_metadata_gracefully()
    {
        // Arrange
        $file = File::factory()->create([
            'source' => 'CivitAI',
            'listing_metadata' => null,
        ]);

        // Act
        $job = new FixDoubleEncodedListingMetadataJob($file->id);
        $job->handle();

        // Assert: Still null
        $file->refresh();
        $this->assertNull($file->listing_metadata);
    }

    /** @test */
    public function it_handles_invalid_json_strings_gracefully()
    {
        // Arrange: Create file with invalid JSON string
        $file = File::factory()->create([
            'source' => 'CivitAI',
        ]);

        \DB::table('files')
            ->where('id', $file->id)
            ->update(['listing_metadata' => json_encode('not valid json at all {]')]);

        // Act: Should not throw exception
        $job = new FixDoubleEncodedListingMetadataJob($file->id);
        $job->handle();

        // Assert: File still exists (didn't crash)
        $this->assertNotNull(File::find($file->id));
    }

    /** @test */
    public function it_dispatches_jobs_for_double_encoded_files()
    {
        Queue::fake();

        // Arrange: Create files with different metadata states
        $doubleEncodedFile = File::factory()->create(['source' => 'CivitAI']);
        \DB::table('files')
            ->where('id', $doubleEncodedFile->id)
            ->update(['listing_metadata' => json_encode(json_encode(['postId' => 1]))]);

        $validFile = File::factory()->create([
            'source' => 'CivitAI',
            'listing_metadata' => ['postId' => 2],
        ]);

        // Act
        $this->artisan('files:fix-double-encoded-metadata')
            ->expectsQuestion('Do you want to dispatch jobs to fix these files?', 'yes')
            ->assertSuccessful();

        // Assert: Only dispatched job for double-encoded file
        Queue::assertPushed(FixDoubleEncodedListingMetadataJob::class, 1);
        Queue::assertPushed(FixDoubleEncodedListingMetadataJob::class, function ($job) use ($doubleEncodedFile) {
            return $job->fileId === $doubleEncodedFile->id;
        });
    }

    /** @test */
    public function it_respects_limit_option()
    {
        Queue::fake();

        // Arrange: Create 5 files with double-encoded metadata
        for ($i = 0; $i < 5; $i++) {
            $file = File::factory()->create(['source' => 'CivitAI']);
            \DB::table('files')
                ->where('id', $file->id)
                ->update(['listing_metadata' => json_encode(json_encode(['postId' => $i]))]);
        }

        // Act: Process only 3
        $this->artisan('files:fix-double-encoded-metadata', ['--limit' => 3])
            ->expectsQuestion('Do you want to dispatch jobs to fix these files?', 'yes')
            ->assertSuccessful();

        // Assert: Only 3 jobs dispatched
        Queue::assertPushed(FixDoubleEncodedListingMetadataJob::class, 3);
    }

    /** @test */
    public function it_shows_message_when_no_files_need_fixing()
    {
        // Arrange: Only files with valid metadata
        File::factory()->create([
            'source' => 'CivitAI',
            'listing_metadata' => ['postId' => 1],
        ]);

        // Act & Assert
        $this->artisan('files:fix-double-encoded-metadata')
            ->expectsOutput('No files with double-encoded metadata found!')
            ->assertSuccessful();
    }

    /** @test */
    public function it_can_dispatch_to_custom_queue()
    {
        Queue::fake();

        // Arrange
        $file = File::factory()->create(['source' => 'CivitAI']);
        \DB::table('files')
            ->where('id', $file->id)
            ->update(['listing_metadata' => json_encode(json_encode(['postId' => 1]))]);

        // Act
        $this->artisan('files:fix-double-encoded-metadata', ['--queue' => 'fix-metadata'])
            ->expectsQuestion('Do you want to dispatch jobs to fix these files?', 'yes')
            ->assertSuccessful();

        // Assert
        Queue::assertPushedOn('fix-metadata', FixDoubleEncodedListingMetadataJob::class);
    }

    /** @test */
    public function it_can_be_cancelled()
    {
        Queue::fake();

        // Arrange
        $file = File::factory()->create(['source' => 'CivitAI']);
        \DB::table('files')
            ->where('id', $file->id)
            ->update(['listing_metadata' => json_encode(json_encode(['postId' => 1]))]);

        // Act
        $this->artisan('files:fix-double-encoded-metadata')
            ->expectsQuestion('Do you want to dispatch jobs to fix these files?', 'no')
            ->expectsOutput('Operation cancelled')
            ->assertSuccessful();

        // Assert: No jobs dispatched
        Queue::assertNothingPushed();
    }
}
