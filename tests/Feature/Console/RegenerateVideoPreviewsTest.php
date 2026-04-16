<?php

use App\Jobs\RegenerateVideoPreviewAssets;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

test('atlas:regenerate-video-previews dispatches jobs for downloaded videos with existing preview assets', function () {
    Bus::fake();

    $videoWithPreview = File::factory()->create([
        'downloaded' => true,
        'path' => 'downloads/video-a.mp4',
        'mime_type' => 'video/mp4',
        'preview_path' => 'downloads/video-a.preview.mp4',
        'poster_path' => 'downloads/video-a.poster.jpg',
    ]);

    $videoWithPosterOnly = File::factory()->create([
        'downloaded' => true,
        'path' => 'downloads/video-b.mp4',
        'mime_type' => 'application/mp4',
        'preview_path' => null,
        'poster_path' => 'downloads/video-b.poster.jpg',
    ]);

    $videoWithoutAssets = File::factory()->create([
        'downloaded' => true,
        'path' => 'downloads/video-c.mp4',
        'mime_type' => 'video/mp4',
        'preview_path' => null,
        'poster_path' => null,
    ]);

    $image = File::factory()->create([
        'downloaded' => true,
        'path' => 'downloads/image.jpg',
        'mime_type' => 'image/jpeg',
        'preview_path' => 'downloads/image.preview.webp',
    ]);

    $this->artisan('atlas:regenerate-video-previews --queue=maintenance')
        ->assertExitCode(0);

    Bus::assertDispatched(RegenerateVideoPreviewAssets::class, fn (RegenerateVideoPreviewAssets $job) => $job->fileId === $videoWithPreview->id && $job->queue === 'maintenance');
    Bus::assertDispatched(RegenerateVideoPreviewAssets::class, fn (RegenerateVideoPreviewAssets $job) => $job->fileId === $videoWithPosterOnly->id && $job->queue === 'maintenance');
    Bus::assertNotDispatched(RegenerateVideoPreviewAssets::class, fn (RegenerateVideoPreviewAssets $job) => $job->fileId === $videoWithoutAssets->id);
    Bus::assertNotDispatched(RegenerateVideoPreviewAssets::class, fn (RegenerateVideoPreviewAssets $job) => $job->fileId === $image->id);
});

test('atlas:regenerate-video-previews dry-run does not dispatch jobs', function () {
    Bus::fake();

    File::factory()->create([
        'downloaded' => true,
        'path' => 'downloads/video-a.mp4',
        'mime_type' => 'video/mp4',
        'preview_path' => 'downloads/video-a.preview.mp4',
        'poster_path' => 'downloads/video-a.poster.jpg',
    ]);

    $this->artisan('atlas:regenerate-video-previews --dry-run')
        ->assertExitCode(0);

    Bus::assertNotDispatched(RegenerateVideoPreviewAssets::class);
});
