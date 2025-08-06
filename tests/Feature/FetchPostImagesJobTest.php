<?php

namespace Tests\Feature;

use App\Events\FileMetadataUpdated;
use App\Jobs\FetchPostImages;
use App\Models\Container;
use App\Models\File;
use App\Models\FileMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FetchPostImagesJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_is_dispatched_when_fetching_posts()
    {
        Queue::fake();

        // Mock the CivitAI API response for posts
        Http::fake([
            'civitai.com/api/trpc/post.getInfinite*' => Http::response([
                'result' => [
                    'data' => [
                        'json' => [
                            'items' => [
                                [
                                    'id' => 123,
                                    'images' => [
                                        [
                                            'id' => 456,
                                            'name' => 'test.jpg',
                                            'url' => 'test-hash',
                                            'width' => 512,
                                            'height' => 512,
                                            'hash' => 'test-hash',
                                            'stats' => []
                                        ]
                                    ]
                                ]
                            ],
                            'nextCursor' => null
                        ]
                    ]
                ]
            ], 200)
        ]);

        // Create a mock request for posts
        $request = \Illuminate\Http\Request::create('/', 'GET', ['container' => 'posts']);

        // Test CivitAIService directly
        $service = new \App\Services\CivitAIService($request);
        $result = $service->fetchPosts();

        // Assert that the service worked
        $this->assertNotEmpty($result);

        // Assert that FetchPostImages job was dispatched
        Queue::assertPushed(FetchPostImages::class);
    }

    public function test_fetch_post_images_job_execution()
    {
        // Create a file with a post container
        $file = File::factory()->create([
            'source' => 'CivitAI',
            'source_id' => '456',
            'is_blacklisted' => false,
        ]);

        $container = Container::factory()->create([
            'type' => 'post',
            'source' => 'CivitAI',
            'source_id' => '123',
        ]);

        // Associate file with container
        $file->containers()->attach($container);

        // Mock the CivitAI API response for images
        Http::fake([
            'civitai.com/api/v1/images*' => Http::response([
                'items' => [
                    [
                        'id' => 456,
                        'url' => 'https://image.civitai.com/existing-image.jpg',
                        'width' => 512,
                        'height' => 512,
                        'hash' => 'existing-hash',
                        'meta' => ['test' => 'data']
                    ],
                    [
                        'id' => 789,
                        'url' => 'https://image.civitai.com/new-image.jpg',
                        'width' => 1024,
                        'height' => 768,
                        'hash' => 'new-hash',
                        'meta' => ['new' => 'metadata']
                    ]
                ]
            ], 200)
        ]);

        // Execute the job
        $job = new FetchPostImages($file);
        $job->handle();

        // Assert that new files were created
        $this->assertDatabaseHas('files', [
            'source_id' => '789',
            'source' => 'CivitAI',
        ]);

        // Assert that metadata was created
        $newFile = File::where('source_id', '789')->first();
        $this->assertNotNull($newFile);
        $this->assertDatabaseHas('file_metadata', [
            'file_id' => $newFile->id,
        ]);

        // Assert that the new file is associated with the container
        $this->assertTrue($container->files()->where('file_id', $newFile->id)->exists());

        // Verify the API was called with correct parameters
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'civitai.com/api/v1/images') &&
                   str_contains($request->url(), 'postId=123') &&
                   str_contains($request->url(), 'limit=200');
        });
    }

    public function test_job_skips_if_no_post_container()
    {
        // Create a file without a post container
        $file = File::factory()->create([
            'source' => 'CivitAI',
            'is_blacklisted' => false,
        ]);

        Http::fake();

        // Execute the job
        $job = new FetchPostImages($file);
        $job->handle();

        // Assert no HTTP requests were made
        Http::assertNothingSent();
    }

    public function test_job_handles_api_failure_gracefully()
    {
        // Create a file with a post container
        $file = File::factory()->create([
            'source' => 'CivitAI',
            'is_blacklisted' => false,
        ]);

        $container = Container::factory()->create([
            'type' => 'post',
            'source' => 'CivitAI',
            'source_id' => '123',
        ]);

        $file->containers()->attach($container);

        // Mock API failure
        Http::fake([
            'civitai.com/api/v1/images*' => Http::response([], 500)
        ]);

        // Execute the job - should handle API failure gracefully
        $job = new FetchPostImages($file);

        // The job should fail gracefully without throwing an uncaught exception
        // We expect it to call $this->fail() internally, which in test context just returns
        $job->handle();

        // Since the API call failed, no new files should be created
        $this->assertDatabaseMissing('files', [
            'source_id' => '789',
            'source' => 'CivitAI',
        ]);

        // Verify the API was called
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'civitai.com/api/v1/images');
        });
    }

    public function test_job_dispatches_metadata_updated_event()
    {
        Event::fake();

        // Create a file with a post container
        $file = File::factory()->create([
            'source' => 'CivitAI',
            'source_id' => '456',
            'is_blacklisted' => false,
        ]);

        $container = Container::factory()->create([
            'type' => 'post',
            'source' => 'CivitAI',
            'source_id' => '123',
        ]);

        // Associate file with container
        $file->containers()->attach($container);

        // Mock the CivitAI API response for images
        Http::fake([
            'civitai.com/api/v1/images*' => Http::response([
                'items' => [
                    [
                        'id' => 789,
                        'url' => 'https://image.civitai.com/new-image.jpg',
                        'width' => 1024,
                        'height' => 768,
                        'hash' => 'new-hash',
                        'meta' => ['new' => 'metadata']
                    ]
                ]
            ], 200)
        ]);

        // Execute the job
        $job = new FetchPostImages($file);
        $job->handle();

        // Assert that FileMetadataUpdated event was dispatched
        Event::assertDispatched(FileMetadataUpdated::class, function ($event) {
            return $event->fileId !== null && 
                   is_array($event->metadata) && 
                   isset($event->metadata['civitai_id']);
        });
    }
}
