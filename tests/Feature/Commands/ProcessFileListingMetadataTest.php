<?php

namespace Tests\Feature\Commands;

use App\Jobs\ProcessFileListingMetadataJob;
use App\Models\Container;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProcessFileListingMetadataTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_dispatches_jobs_for_civitai_files_with_metadata()
    {
        Queue::fake();

        // Arrange: Create a CivitAI file with listing metadata
        $file = File::factory()->create([
            'source' => 'CivitAI',
            'listing_metadata' => [
                'postId' => 98765,
                'username' => 'test_user',
            ],
        ]);

        // Act: Run the command
        $this->artisan('files:process-listing-metadata')
            ->assertSuccessful();

        // Assert: Job was dispatched
        Queue::assertPushed(ProcessFileListingMetadataJob::class, 1);
        Queue::assertPushed(ProcessFileListingMetadataJob::class, function ($job) use ($file) {
            return $job->fileId === $file->id;
        });
    }

    /** @test */
    public function it_creates_post_and_user_containers_from_civitai_file_metadata()
    {
        // Arrange: Create a CivitAI file with listing metadata containing postId and username
        $file = File::factory()->create([
            'source' => 'CivitAI',
            'source_id' => 'test-source-123',
            'referrer_url' => 'https://civitai.com/images/12345',
            'listing_metadata' => [
                'postId' => 98765,
                'username' => 'test_user',
                'other_field' => 'some value',
            ],
        ]);

        $this->assertDatabaseCount('containers', 0);
        $this->assertDatabaseCount('container_file', 0);

        // Act: Execute the job directly
        $job = new ProcessFileListingMetadataJob($file->id);
        $job->handle();

        // Assert: Post container was created with correct referrer
        $this->assertDatabaseHas('containers', [
            'type' => 'Post',
            'source' => 'CivitAI',
            'source_id' => '98765',
            'referrer' => 'https://civitai.com/posts/98765',
        ]);

        // Assert: User container was created with correct referrer
        $this->assertDatabaseHas('containers', [
            'type' => 'User',
            'source' => 'CivitAI',
            'source_id' => 'test_user',
            'referrer' => 'https://civitai.com/user/test_user',
        ]);

        // Assert: File is attached to both containers
        $this->assertDatabaseCount('containers', 2);
        $this->assertDatabaseCount('container_file', 2);

        $postContainer = Container::where('type', 'Post')->first();
        $userContainer = Container::where('type', 'User')->first();

        $this->assertTrue($file->containers()->where('containers.id', $postContainer->id)->exists());
        $this->assertTrue($file->containers()->where('containers.id', $userContainer->id)->exists());
    }

    /** @test */
    public function it_only_dispatches_jobs_for_civitai_files()
    {
        Queue::fake();

        // Arrange: Create files from different sources
        $civitaiFile = File::factory()->create([
            'source' => 'CivitAI',
            'listing_metadata' => [
                'postId' => 123,
                'username' => 'user1',
            ],
        ]);

        $otherSourceFile = File::factory()->create([
            'source' => 'other_source',
            'listing_metadata' => [
                'postId' => 456,
                'username' => 'user2',
            ],
        ]);

        // Act
        $this->artisan('files:process-listing-metadata')
            ->assertSuccessful();

        // Assert: Only CivitAI file job was dispatched
        Queue::assertPushed(ProcessFileListingMetadataJob::class, 1);
        Queue::assertPushed(ProcessFileListingMetadataJob::class, function ($job) use ($civitaiFile) {
            return $job->fileId === $civitaiFile->id;
        });
    }

    /** @test */
    public function it_handles_files_with_only_post_id()
    {
        // Arrange
        $file = File::factory()->create([
            'source' => 'CivitAI',
            'listing_metadata' => [
                'postId' => 555,
            ],
        ]);

        // Act: Execute the job directly
        $job = new ProcessFileListingMetadataJob($file->id);
        $job->handle();

        // Assert
        $this->assertDatabaseCount('containers', 1);
        $this->assertDatabaseHas('containers', [
            'type' => 'Post',
            'source_id' => '555',
        ]);
    }

    /** @test */
    public function it_handles_files_with_only_username()
    {
        // Arrange
        $file = File::factory()->create([
            'source' => 'CivitAI',
            'listing_metadata' => [
                'username' => 'solo_user',
            ],
        ]);

        // Act: Execute the job directly
        $job = new ProcessFileListingMetadataJob($file->id);
        $job->handle();

        // Assert
        $this->assertDatabaseCount('containers', 1);
        $this->assertDatabaseHas('containers', [
            'type' => 'User',
            'source_id' => 'solo_user',
        ]);
    }

    /** @test */
    public function it_does_not_dispatch_jobs_for_files_without_listing_metadata()
    {
        Queue::fake();

        // Arrange
        File::factory()->create([
            'source' => 'CivitAI',
            'listing_metadata' => null,
        ]);

        // Act
        $this->artisan('files:process-listing-metadata')
            ->assertSuccessful();

        // Assert: No jobs dispatched
        Queue::assertNothingPushed();
    }

    /** @test */
    public function it_reuses_existing_containers()
    {
        // Arrange: Create existing containers
        $postContainer = Container::create([
            'type' => 'Post',
            'source' => 'CivitAI',
            'source_id' => '999',
        ]);

        $userContainer = Container::create([
            'type' => 'User',
            'source' => 'CivitAI',
            'source_id' => 'existing_user',
        ]);

        // Create files with same metadata
        $file1 = File::factory()->create([
            'source' => 'CivitAI',
            'listing_metadata' => [
                'postId' => 999,
                'username' => 'existing_user',
            ],
        ]);

        $file2 = File::factory()->create([
            'source' => 'CivitAI',
            'listing_metadata' => [
                'postId' => 999,
                'username' => 'existing_user',
            ],
        ]);

        $this->assertDatabaseCount('containers', 2);

        // Act: Execute jobs directly
        (new ProcessFileListingMetadataJob($file1->id))->handle();
        (new ProcessFileListingMetadataJob($file2->id))->handle();

        // Assert: No new containers created, only attachments
        $this->assertDatabaseCount('containers', 2);
        $this->assertDatabaseCount('container_file', 4); // 2 files × 2 containers

        $this->assertTrue($file1->fresh()->containers()->where('containers.id', $postContainer->id)->exists());
        $this->assertTrue($file1->fresh()->containers()->where('containers.id', $userContainer->id)->exists());
        $this->assertTrue($file2->fresh()->containers()->where('containers.id', $postContainer->id)->exists());
        $this->assertTrue($file2->fresh()->containers()->where('containers.id', $userContainer->id)->exists());
    }

    /** @test */
    public function it_does_not_duplicate_attachments()
    {
        // Arrange: Create file with metadata
        $file = File::factory()->create([
            'source' => 'CivitAI',
            'listing_metadata' => [
                'postId' => 777,
                'username' => 'duplicate_test',
            ],
        ]);

        // Run job first time
        $job = new ProcessFileListingMetadataJob($file->id);
        $job->handle();

        $this->assertDatabaseCount('container_file', 2);

        // Act: Run job again
        $job = new ProcessFileListingMetadataJob($file->id);
        $job->handle();

        // Assert: No duplicate attachments
        $this->assertDatabaseCount('container_file', 2);
    }

    /** @test */
    public function it_respects_limit_option()
    {
        Queue::fake();

        // Arrange: Create 5 files
        File::factory()->count(5)->create([
            'source' => 'CivitAI',
            'listing_metadata' => [
                'postId' => 123,
            ],
        ]);

        // Act: Process only 3
        $this->artisan('files:process-listing-metadata', ['--limit' => 3])
            ->assertSuccessful();

        // Assert: Only 3 jobs were dispatched
        Queue::assertPushed(ProcessFileListingMetadataJob::class, 3);
    }

    /** @test */
    public function it_handles_numeric_and_string_source_ids()
    {
        // Arrange
        $file = File::factory()->create([
            'source' => 'CivitAI',
            'listing_metadata' => [
                'postId' => 42, // numeric
                'username' => 'string_user', // string
            ],
        ]);

        // Act: Execute job directly
        $job = new ProcessFileListingMetadataJob($file->id);
        $job->handle();

        // Assert: Both are stored as strings in database with correct referrers
        $this->assertDatabaseHas('containers', [
            'type' => 'Post',
            'source_id' => '42',
            'referrer' => 'https://civitai.com/posts/42',
        ]);

        $this->assertDatabaseHas('containers', [
            'type' => 'User',
            'source_id' => 'string_user',
            'referrer' => 'https://civitai.com/user/string_user',
        ]);
    }

    /** @test */
    public function it_dispatches_correct_number_of_jobs()
    {
        Queue::fake();

        // Arrange
        File::factory()->count(3)->create([
            'source' => 'CivitAI',
            'listing_metadata' => [
                'postId' => 1,
            ],
        ]);

        // Act
        $this->artisan('files:process-listing-metadata')
            ->assertSuccessful();

        // Assert: 3 jobs dispatched
        Queue::assertPushed(ProcessFileListingMetadataJob::class, 3);
    }

    /** @test */
    public function it_sets_correct_referrer_urls_for_containers()
    {
        // Arrange
        $file = File::factory()->create([
            'source' => 'CivitAI',
            'listing_metadata' => [
                'postId' => 12345,
                'username' => 'test_artist',
            ],
        ]);

        // Act: Execute job directly
        $job = new ProcessFileListingMetadataJob($file->id);
        $job->handle();

        // Assert: Post container has correct referrer
        $postContainer = Container::where('type', 'Post')
            ->where('source_id', '12345')
            ->first();

        $this->assertNotNull($postContainer);
        $this->assertEquals('https://civitai.com/posts/12345', $postContainer->referrer);

        // Assert: User container has correct referrer
        $userContainer = Container::where('type', 'User')
            ->where('source_id', 'test_artist')
            ->first();

        $this->assertNotNull($userContainer);
        $this->assertEquals('https://civitai.com/user/test_artist', $userContainer->referrer);
    }

    /** @test */
    public function it_dispatches_jobs_to_specified_queue()
    {
        Queue::fake();

        // Arrange
        $file = File::factory()->create([
            'source' => 'CivitAI',
            'listing_metadata' => ['postId' => 1],
        ]);

        // Act
        $this->artisan('files:process-listing-metadata', ['--queue' => 'custom-queue'])
            ->assertSuccessful();

        // Assert: Job dispatched to custom queue
        Queue::assertPushedOn('custom-queue', ProcessFileListingMetadataJob::class);
    }

    /** @test */
    public function it_skips_files_with_existing_containers_when_flag_is_set()
    {
        Queue::fake();

        // Arrange: Create file with containers
        $fileWithContainers = File::factory()->create([
            'source' => 'CivitAI',
            'listing_metadata' => ['postId' => 1],
        ]);
        $container = Container::create([
            'type' => 'Post',
            'source' => 'CivitAI',
            'source_id' => '1',
        ]);
        $fileWithContainers->containers()->attach($container);

        // Create file without containers
        $fileWithoutContainers = File::factory()->create([
            'source' => 'CivitAI',
            'listing_metadata' => ['postId' => 2],
        ]);

        // Act: Run with skip-processed flag
        $this->artisan('files:process-listing-metadata', ['--skip-processed' => true])
            ->assertSuccessful();

        // Assert: Only dispatched job for file without containers
        Queue::assertPushed(ProcessFileListingMetadataJob::class, 1);
        Queue::assertPushed(ProcessFileListingMetadataJob::class, function ($job) use ($fileWithoutContainers) {
            return $job->fileId === $fileWithoutContainers->id;
        });
    }

    /** @test */
    public function it_processes_all_files_when_skip_processed_flag_is_not_set()
    {
        Queue::fake();

        // Arrange: Create file with containers
        $fileWithContainers = File::factory()->create([
            'source' => 'CivitAI',
            'listing_metadata' => ['postId' => 1],
        ]);
        $container = Container::create([
            'type' => 'Post',
            'source' => 'CivitAI',
            'source_id' => '1',
        ]);
        $fileWithContainers->containers()->attach($container);

        // Create file without containers
        $fileWithoutContainers = File::factory()->create([
            'source' => 'CivitAI',
            'listing_metadata' => ['postId' => 2],
        ]);

        // Act: Run without skip-processed flag
        $this->artisan('files:process-listing-metadata')
            ->assertSuccessful();

        // Assert: Dispatched jobs for both files
        Queue::assertPushed(ProcessFileListingMetadataJob::class, 2);
    }
}
