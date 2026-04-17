<?php

use App\Enums\DownloadChunkStatus;
use App\Enums\DownloadTransferStatus;
use App\Jobs\Downloads\DownloadTransferChunk;
use App\Models\DownloadChunk;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Services\Downloads\DownloadTransferProgressBroadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('retries transient chunk stream read failures and resets chunk progress', function () {
    Http::fake(function () {
        throw new RuntimeException('Unable to read from stream');
    });

    $file = File::factory()->create([
        'source' => 'Extension',
        'url' => 'https://image.civitai.com/example/original=true/example.jpeg',
        'referrer_url' => 'https://civitai.com/images/1',
        'downloaded' => false,
        'path' => null,
        'download_progress' => 40,
    ]);

    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => (string) $file->url,
        'domain' => 'image.civitai.com',
        'status' => DownloadTransferStatus::DOWNLOADING,
        'bytes_total' => 20,
        'bytes_downloaded' => 8,
        'last_broadcast_percent' => 40,
        'started_at' => now(),
    ]);

    $chunk = DownloadChunk::query()->create([
        'download_transfer_id' => $transfer->id,
        'index' => 1,
        'range_start' => 5,
        'range_end' => 9,
        'bytes_downloaded' => 8,
        'status' => DownloadChunkStatus::DOWNLOADING,
        'part_path' => "downloads/.tmp/transfer-{$transfer->id}/part-1.part",
        'started_at' => now(),
    ]);

    $job = new DownloadTransferChunk($transfer->id, $chunk->id, 'image/jpeg');
    $job->withFakeQueueInteractions();
    $job->handle(
        app(DownloadTransferProgressBroadcaster::class),
        app(\App\Services\Downloads\DownloadTransferRequestOptions::class),
    );

    $job->assertReleased(30);

    $transfer->refresh();
    $chunk->refresh();
    $file->refresh();

    expect($transfer->status)->toBe(DownloadTransferStatus::DOWNLOADING)
        ->and($transfer->failed_at)->toBeNull()
        ->and($transfer->bytes_downloaded)->toBe(0)
        ->and($transfer->last_broadcast_percent)->toBe(0)
        ->and($transfer->error)->toContain('Retry 1/3 scheduled in 30s')
        ->and($transfer->error)->toContain('Unable to read from stream')
        ->and($chunk->status)->toBe(DownloadChunkStatus::PENDING)
        ->and($chunk->bytes_downloaded)->toBe(0)
        ->and($chunk->failed_at)->toBeNull()
        ->and($chunk->error)->toContain('Retry 1/3 scheduled in 30s')
        ->and($file->download_progress)->toBe(0);
});
