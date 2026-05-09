<?php

use App\Jobs\GenerateFilePreviewAssets;
use App\Models\File;
use App\Services\Downloads\FileDownloadFinalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

it('passes the force flag to preview generation and stores returned paths', function () {
    $file = File::factory()->create([
        'preview_path' => 'downloads/aa/bb/thumbnails/old.jpg',
        'poster_path' => null,
    ]);

    mock(FileDownloadFinalizer::class)
        ->shouldReceive('generatePreviewAssets')
        ->once()
        ->with(\Mockery::on(fn (File $argument): bool => $argument->is($file)), true)
        ->andReturn([
            'preview_path' => 'downloads/aa/bb/preview/new.jpg',
        ]);

    (new GenerateFilePreviewAssets($file->id, true))->handle(app(FileDownloadFinalizer::class));

    expect($file->fresh()?->preview_path)->toBe('downloads/aa/bb/preview/new.jpg');
});
