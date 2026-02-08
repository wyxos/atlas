<?php

use App\Enums\DownloadTransferStatus;
use App\Jobs\Downloads\DownloadTransferSingleStream;
use App\Jobs\Downloads\DownloadTransferYtDlp;
use App\Jobs\Downloads\PrepareDownloadTransfer;
use App\Models\DownloadTransfer;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('falls back to yt-dlp when a video url resolves to HTML', function () {
    Bus::fake([
        DownloadTransferYtDlp::class,
        DownloadTransferSingleStream::class,
    ]);

    $url = 'https://www.youtube.com/embed/MD99_p7XLD8';

    Http::fake(function (Request $request) use ($url) {
        if ($request->method() !== 'HEAD') {
            throw new RuntimeException('Unexpected HTTP method: '.$request->method());
        }

        if ((string) $request->url() !== $url) {
            throw new RuntimeException('Unexpected URL: '.$request->url());
        }

        return Http::response('', 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Length' => '1024',
            'Accept-Ranges' => 'none',
        ]);
    });

    $file = File::factory()->create([
        'source' => 'Extension',
        'url' => $url,
        'referrer_url' => $url,
        'downloaded' => false,
        'path' => null,
        'listing_metadata' => ['tag_name' => 'video'],
    ]);

    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $file->url,
        'domain' => 'youtube.com',
        'status' => DownloadTransferStatus::QUEUED,
        'bytes_total' => null,
        'bytes_downloaded' => 0,
        'last_broadcast_percent' => 0,
    ]);

    PrepareDownloadTransfer::dispatchSync($transfer->id);

    $transfer->refresh();
    expect($transfer->status)->toBe(DownloadTransferStatus::DOWNLOADING);

    $file->refresh();
    expect(data_get($file->listing_metadata, 'download_via'))->toBe('yt-dlp');

    Bus::assertDispatched(DownloadTransferYtDlp::class, function (DownloadTransferYtDlp $job) use ($transfer) {
        return $job->downloadTransferId === $transfer->id;
    });

    Bus::assertNotDispatched(DownloadTransferSingleStream::class);
});
