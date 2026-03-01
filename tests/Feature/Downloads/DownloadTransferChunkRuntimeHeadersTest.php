<?php

use App\Enums\DownloadChunkStatus;
use App\Enums\DownloadTransferStatus;
use App\Jobs\Downloads\DownloadTransferChunk;
use App\Models\DownloadChunk;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Services\Downloads\DownloadTransferProgressBroadcaster;
use App\Services\Downloads\DownloadTransferRuntimeStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('includes runtime cookies and user agent in chunk download headers', function () {
    $file = File::factory()->create([
        'url' => 'https://cdn.example.test/files/video.mp4',
        'referrer_url' => 'https://www.example.test/post/456',
        'downloaded' => false,
        'path' => null,
    ]);

    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $file->url,
        'domain' => 'cdn.example.test',
        'status' => DownloadTransferStatus::DOWNLOADING,
        'bytes_total' => 3,
        'bytes_downloaded' => 0,
        'last_broadcast_percent' => 0,
    ]);

    $chunk = DownloadChunk::query()->create([
        'download_transfer_id' => $transfer->id,
        'index' => 0,
        'range_start' => 0,
        'range_end' => 2,
        'bytes_downloaded' => 0,
        'status' => DownloadChunkStatus::PENDING,
        'part_path' => "downloads/.tmp/transfer-{$transfer->id}/part-0.part",
    ]);

    app(DownloadTransferRuntimeStore::class)->putForTransfer($transfer->id, [
        'cookies' => 'auth=abc; session=xyz',
        'user_agent' => 'AtlasExtensionRuntime/1.0',
    ]);

    Http::fake([
        '*' => Http::response('abc', 206, [
            'Content-Range' => 'bytes 0-2/3',
        ]),
    ]);

    (new DownloadTransferChunk($transfer->id, $chunk->id, 'video/mp4'))->handle(
        app(DownloadTransferProgressBroadcaster::class),
        app(\App\Services\Downloads\DownloadTransferRequestOptions::class),
    );

    Http::assertSent(function (Request $request): bool {
        return $request->header('Referer')[0] === 'https://www.example.test/post/456'
            && $request->header('User-Agent')[0] === 'AtlasExtensionRuntime/1.0'
            && $request->header('Cookie')[0] === 'auth=abc; session=xyz'
            && $request->header('Range')[0] === 'bytes=0-2';
    });

    $chunk->refresh();
    expect($chunk->status)->toBe(DownloadChunkStatus::COMPLETED);
});
