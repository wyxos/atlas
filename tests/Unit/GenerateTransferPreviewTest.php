<?php

use App\Enums\DownloadTransferStatus;
use App\Jobs\Downloads\GenerateTransferPreview;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Services\Downloads\FileDownloadFinalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

it('completes the transfer after preview generation', function () {
    $file = File::factory()->create([
        'downloaded' => true,
        'path' => 'downloads/aa/bb/test.mp4',
        'preview_path' => null,
        'mime_type' => 'video/mp4',
    ]);

    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => 'https://example.com/file.mp4',
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::PREVIEWING,
        'bytes_total' => 100,
        'bytes_downloaded' => 100,
        'last_broadcast_percent' => 100,
    ]);

    mock(FileDownloadFinalizer::class)
        ->shouldReceive('generatePreviewAssets')
        ->once()
        ->andReturn(['preview_path' => 'downloads/aa/bb/test.preview.mp4']);

    $job = new GenerateTransferPreview($transfer->id);
    $job->handle(app(FileDownloadFinalizer::class));

    $transfer->refresh();
    $file->refresh();

    expect($transfer->status)->toBe(DownloadTransferStatus::COMPLETED);
    expect($transfer->finished_at)->not->toBeNull();
    expect($file->preview_path)->toBe('downloads/aa/bb/test.preview.mp4');
});
