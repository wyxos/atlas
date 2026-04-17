<?php

use App\Enums\DownloadTransferStatus;
use App\Jobs\Downloads\DownloadTransferSingleStream;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Services\Downloads\DownloadTransferProgressBroadcaster;
use App\Services\Downloads\FileDownloadFinalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

it('retries transient single stream read failures and resets visible progress', function () {
    Http::fake(function () {
        throw new RuntimeException('Unable to read from stream');
    });

    $file = File::factory()->create([
        'source' => 'Extension',
        'url' => 'https://image.civitai.com/example/original=true/example.jpeg',
        'referrer_url' => 'https://civitai.com/images/1',
        'downloaded' => false,
        'path' => null,
        'download_progress' => 25,
    ]);

    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => (string) $file->url,
        'domain' => 'image.civitai.com',
        'status' => DownloadTransferStatus::DOWNLOADING,
        'bytes_total' => 100,
        'bytes_downloaded' => 25,
        'last_broadcast_percent' => 25,
        'started_at' => now(),
    ]);

    $this->mock(FileDownloadFinalizer::class, function (MockInterface $mock): void {
        $mock->shouldReceive('finalize')->never();
    });

    $job = new DownloadTransferSingleStream($transfer->id, 'image/jpeg');
    $job->withFakeQueueInteractions();
    $job->handle(
        app(FileDownloadFinalizer::class),
        app(DownloadTransferProgressBroadcaster::class),
        app(\App\Services\Downloads\DownloadTransferRequestOptions::class),
    );

    $job->assertReleased(30);

    $transfer->refresh();
    $file->refresh();

    expect($transfer->status)->toBe(DownloadTransferStatus::DOWNLOADING)
        ->and($transfer->failed_at)->toBeNull()
        ->and($transfer->bytes_downloaded)->toBe(0)
        ->and($transfer->last_broadcast_percent)->toBe(0)
        ->and($transfer->error)->toContain('Retry 1/3 scheduled in 30s')
        ->and($transfer->error)->toContain('Unable to read from stream')
        ->and($file->download_progress)->toBe(0);
});
