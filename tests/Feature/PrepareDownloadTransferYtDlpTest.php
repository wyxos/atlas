<?php

use App\Enums\DownloadTransferStatus;
use App\Jobs\Downloads\DownloadTransferYtDlp;
use App\Jobs\Downloads\PrepareDownloadTransfer;
use App\Models\DownloadTransfer;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('dispatches yt-dlp downloader when download_via is yt-dlp', function () {
    Bus::fake([
        DownloadTransferYtDlp::class,
    ]);

    // If this path is correct, we should not do any HTTP HEAD probes.
    Http::fake(function () {
        throw new RuntimeException('HTTP should not be called for yt-dlp transfers.');
    });

    $file = File::factory()->create([
        'source' => 'Extension',
        'url' => 'https://example.com/watch?v=123',
        'referrer_url' => 'https://example.com/watch?v=123',
        'downloaded' => false,
        'path' => null,
        'listing_metadata' => ['download_via' => 'yt-dlp', 'tag_name' => 'video'],
    ]);

    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $file->url,
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::QUEUED,
        'bytes_total' => null,
        'bytes_downloaded' => 0,
        'last_broadcast_percent' => 0,
    ]);

    $transfer->load('file');
    expect(data_get($transfer->file->listing_metadata, 'download_via'))->toBe('yt-dlp');

    PrepareDownloadTransfer::dispatchSync($transfer->id);

    $transfer->refresh();
    expect($transfer->status)->toBe(DownloadTransferStatus::DOWNLOADING);

    Bus::assertDispatched(DownloadTransferYtDlp::class, function (DownloadTransferYtDlp $job) use ($transfer) {
        return $job->downloadTransferId === $transfer->id;
    });
});
