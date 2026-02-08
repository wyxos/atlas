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
        'preview_path' => 'previews/test.webp',
        'poster_path' => null,
    ]);

    $alreadyOk = File::factory()->create([
        'downloaded' => true,
        'path' => 'downloads/ok.jpg',
        'mime_type' => 'image/jpeg',
        'preview_path' => 'previews/ok.webp',
    ]);

    $this->artisan('atlas:queue-missing-previews --queue=processing')
        ->assertExitCode(0);

    Bus::assertDispatched(GenerateFilePreviewAssets::class, fn (GenerateFilePreviewAssets $job) => $job->fileId === $image->id);
    Bus::assertDispatched(GenerateFilePreviewAssets::class, fn (GenerateFilePreviewAssets $job) => $job->fileId === $video->id);
    Bus::assertNotDispatched(GenerateFilePreviewAssets::class, fn (GenerateFilePreviewAssets $job) => $job->fileId === $alreadyOk->id);
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
