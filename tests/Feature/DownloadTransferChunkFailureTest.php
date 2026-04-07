<?php

use App\Enums\DownloadChunkStatus;
use App\Enums\DownloadTransferStatus;
use App\Jobs\Downloads\DownloadTransferChunk;
use App\Jobs\Downloads\PumpDomainDownloads;
use App\Models\DownloadChunk;
use App\Models\DownloadTransfer;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('marks the transfer failed when a chunk job times out outside the job handler', function () {
    Bus::fake();

    $file = File::factory()->create([
        'source' => 'Extension',
        'url' => 'https://example.com/video.mp4',
        'referrer_url' => 'https://example.com/view/video',
        'downloaded' => false,
        'path' => null,
    ]);

    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $file->url,
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::DOWNLOADING,
        'bytes_total' => 20 * 1024 * 1024,
        'bytes_downloaded' => 10 * 1024 * 1024,
        'last_broadcast_percent' => 50,
        'started_at' => now(),
    ]);

    $chunk = DownloadChunk::query()->create([
        'download_transfer_id' => $transfer->id,
        'index' => 0,
        'range_start' => 0,
        'range_end' => (10 * 1024 * 1024) - 1,
        'bytes_downloaded' => 10 * 1024 * 1024,
        'status' => DownloadChunkStatus::DOWNLOADING,
        'part_path' => 'tmp/transfer-'.$transfer->id.'/part-0.part',
        'started_at' => now(),
    ]);

    (new DownloadTransferChunk($transfer->id, $chunk->id, 'video/mp4'))
        ->failed(new RuntimeException('Chunk download timed out.'));

    $transfer->refresh();
    $chunk->refresh();

    expect($transfer->status)->toBe(DownloadTransferStatus::FAILED)
        ->and($transfer->failed_at)->not->toBeNull()
        ->and($transfer->error)->toContain('Chunk download timed out.')
        ->and($chunk->status)->toBe(DownloadChunkStatus::FAILED)
        ->and($chunk->failed_at)->not->toBeNull()
        ->and($chunk->error)->toContain('Chunk download timed out.');

    Bus::assertDispatched(PumpDomainDownloads::class, fn (PumpDomainDownloads $job): bool => $job->domain === 'example.com');
});
