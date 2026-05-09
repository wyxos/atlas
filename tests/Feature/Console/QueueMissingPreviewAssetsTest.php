<?php

use App\Jobs\GenerateFilePreviewAssets;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

test('atlas:queue-missing-previews dispatches jobs for downloaded files missing preview assets', function () {
    Bus::fake();

    $image = File::factory()->create([
        'downloaded' => true,
        'path' => 'downloads/test.jpg',
        'mime_type' => 'image/jpeg',
        'preview_path' => null,
    ]);

    $video = File::factory()->create([
        'downloaded' => true,
        'path' => 'downloads/test.mp4',
        'mime_type' => 'video/mp4',
        'preview_path' => 'downloads/preview/test.mp4',
        'poster_path' => null,
    ]);

    $applicationMp4Video = File::factory()->create([
        'downloaded' => true,
        'path' => 'downloads/application-mp4.mp4',
        'mime_type' => 'application/mp4',
        'preview_path' => 'downloads/preview/application-mp4.mp4',
        'poster_path' => null,
    ]);

    $alreadyOk = File::factory()->create([
        'downloaded' => true,
        'path' => 'downloads/ok.jpg',
        'mime_type' => 'image/jpeg',
        'preview_path' => 'downloads/preview/ok.jpg',
    ]);

    $this->artisan('atlas:queue-missing-previews --queue=processing')
        ->assertExitCode(0);

    Bus::assertDispatched(GenerateFilePreviewAssets::class, fn (GenerateFilePreviewAssets $job) => $job->fileId === $image->id && $job->force === false);
    Bus::assertDispatched(GenerateFilePreviewAssets::class, fn (GenerateFilePreviewAssets $job) => $job->fileId === $video->id && $job->force === false);
    Bus::assertDispatched(GenerateFilePreviewAssets::class, fn (GenerateFilePreviewAssets $job) => $job->fileId === $applicationMp4Video->id && $job->force === false);
    Bus::assertNotDispatched(GenerateFilePreviewAssets::class, fn (GenerateFilePreviewAssets $job) => $job->fileId === $alreadyOk->id);
});

test('atlas:queue-missing-previews ignores stale generated paths unless requested', function () {
    Bus::fake();

    $staleImage = File::factory()->create([
        'downloaded' => true,
        'path' => 'downloads/aa/bb/stale.jpg',
        'mime_type' => 'image/jpeg',
        'preview_path' => 'downloads/aa/bb/thumbnails/stale.jpg',
    ]);

    $this->artisan('atlas:queue-missing-previews --queue=processing')
        ->assertExitCode(0);

    Bus::assertNotDispatched(GenerateFilePreviewAssets::class, fn (GenerateFilePreviewAssets $job) => $job->fileId === $staleImage->id);
});

test('atlas:queue-missing-previews can force queue stale generated paths', function () {
    Bus::fake();

    $staleImage = File::factory()->create([
        'downloaded' => true,
        'path' => 'downloads/aa/bb/stale.jpg',
        'mime_type' => 'image/jpeg',
        'preview_path' => 'downloads/aa/bb/thumbnails/stale.jpg',
    ]);
    $staleVideo = File::factory()->create([
        'downloaded' => true,
        'path' => 'downloads/aa/bb/stale-video.mp4',
        'mime_type' => 'video/mp4',
        'preview_path' => 'downloads/aa/bb/preview/stale-video.mp4',
        'poster_path' => 'downloads/aa/bb/stale-video.poster.jpg',
    ]);
    $alreadyOk = File::factory()->create([
        'downloaded' => true,
        'path' => 'downloads/aa/bb/ok.jpg',
        'mime_type' => 'image/jpeg',
        'preview_path' => 'downloads/aa/bb/preview/ok.jpg',
    ]);

    $this->artisan('atlas:queue-missing-previews --include-stale-paths --queue=processing')
        ->assertExitCode(0);

    Bus::assertDispatched(GenerateFilePreviewAssets::class, fn (GenerateFilePreviewAssets $job) => $job->fileId === $staleImage->id && $job->force === true);
    Bus::assertDispatched(GenerateFilePreviewAssets::class, fn (GenerateFilePreviewAssets $job) => $job->fileId === $staleVideo->id && $job->force === true);
    Bus::assertNotDispatched(GenerateFilePreviewAssets::class, fn (GenerateFilePreviewAssets $job) => $job->fileId === $alreadyOk->id);
});

test('atlas:queue-missing-previews dry-run counts stale generated paths when requested', function () {
    Bus::fake();

    File::factory()->create([
        'downloaded' => true,
        'path' => 'downloads/aa/bb/stale.jpg',
        'mime_type' => 'image/jpeg',
        'preview_path' => 'thumbnails/stale.jpg',
    ]);

    $this->artisan('atlas:queue-missing-previews --include-stale-paths --dry-run')
        ->expectsOutput('Would queue 1 file(s).')
        ->assertExitCode(0);

    Bus::assertNotDispatched(GenerateFilePreviewAssets::class);
});

test('atlas:queue-missing-previews dry-run does not dispatch jobs', function () {
    Bus::fake();

    File::factory()->create([
        'downloaded' => true,
        'path' => 'downloads/test.jpg',
        'mime_type' => 'image/jpeg',
        'preview_path' => null,
    ]);

    $this->artisan('atlas:queue-missing-previews --dry-run')
        ->assertExitCode(0);

    Bus::assertNotDispatched(GenerateFilePreviewAssets::class);
});
