<?php

use App\Jobs\RegenerateVideoPreviewAssets;
use App\Models\File;
use App\Services\Downloads\FileDownloadFinalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

it('regenerates preview assets for a video file', function () {
    $file = File::factory()->create([
        'downloaded' => true,
        'path' => 'downloads/aa/bb/test.mp4',
        'preview_path' => 'downloads/aa/bb/test.preview.mp4',
        'poster_path' => 'downloads/aa/bb/test.poster.jpg',
        'mime_type' => 'video/mp4',
    ]);

    mock(FileDownloadFinalizer::class)
        ->shouldReceive('regenerateVideoPreviewAssets')
        ->once()
        ->andReturn([
            'preview_path' => 'downloads/aa/bb/test.preview.mp4',
            'poster_path' => 'downloads/aa/bb/test.poster.jpg',
        ]);

    $job = new RegenerateVideoPreviewAssets($file->id);
    $job->handle(app(FileDownloadFinalizer::class));

    expect($file->fresh()?->preview_path)->toBe('downloads/aa/bb/test.preview.mp4');
    expect($file->fresh()?->poster_path)->toBe('downloads/aa/bb/test.poster.jpg');
});
