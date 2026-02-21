<?php

use App\Enums\DownloadTransferStatus;
use App\Jobs\Downloads\PrepareDownloadTransfer;
use App\Models\DownloadTransfer;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('releases retry for transient prepare failures and keeps transfer visible in queue', function () {
    Http::fake(function () {
        throw new RuntimeException('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received');
    });

    $file = File::factory()->create([
        'source' => 'Extension',
        'url' => 'https://image.civitai.com/example/original=true/example.jpeg',
        'referrer_url' => 'https://civitai.com/images/1',
        'downloaded' => false,
        'path' => null,
        'listing_metadata' => ['tag_name' => 'img'],
    ]);

    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => (string) $file->url,
        'domain' => 'image.civitai.com',
        'status' => DownloadTransferStatus::QUEUED,
        'bytes_total' => null,
        'bytes_downloaded' => 0,
        'last_broadcast_percent' => 0,
        'queued_at' => now(),
    ]);

    $job = new PrepareDownloadTransfer($transfer->id);
    $job->withFakeQueueInteractions();
    $job->handle();

    $job->assertReleased(30);

    $transfer->refresh();

    expect($transfer->status)->toBe(DownloadTransferStatus::QUEUED)
        ->and($transfer->failed_at)->toBeNull()
        ->and($transfer->error)->toContain('Retry 1/3 scheduled in 30s')
        ->and($transfer->error)->toContain('cURL error 28');
});
