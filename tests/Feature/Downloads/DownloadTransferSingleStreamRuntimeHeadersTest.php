<?php

use App\Enums\DownloadTransferStatus;
use App\Jobs\Downloads\DownloadTransferSingleStream;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Services\Downloads\DownloadTransferProgressBroadcaster;
use App\Services\Downloads\DownloadTransferRuntimeStore;
use App\Services\Downloads\FileDownloadFinalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

it('includes runtime cookies and user agent in single stream download headers', function () {
    $file = File::factory()->create([
        'url' => 'https://cdn.example.test/files/image.jpg',
        'referrer_url' => 'https://www.example.test/post/123',
        'downloaded' => false,
        'path' => null,
    ]);

    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $file->url,
        'domain' => 'cdn.example.test',
        'status' => DownloadTransferStatus::DOWNLOADING,
        'bytes_total' => null,
        'bytes_downloaded' => 0,
        'last_broadcast_percent' => 0,
    ]);

    app(DownloadTransferRuntimeStore::class)->putForTransfer($transfer->id, [
        'cookies' => [
            [
                'name' => 'auth',
                'value' => 'abc',
                'domain' => 'cdn.example.test',
                'path' => '/',
                'secure' => true,
                'http_only' => true,
                'host_only' => false,
                'expires_at' => time() + 3600,
            ],
            [
                'name' => 'other',
                'value' => 'zzz',
                'domain' => 'other.example.test',
                'path' => '/',
                'secure' => true,
                'http_only' => false,
                'host_only' => false,
                'expires_at' => time() + 3600,
            ],
        ],
        'user_agent' => 'AtlasExtensionRuntime/1.0',
    ]);

    Http::fake([
        '*' => Http::response('abc', 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => '3',
        ]),
    ]);

    $this->mock(FileDownloadFinalizer::class, function (MockInterface $mock): void {
        $mock->shouldReceive('finalize')->once();
    });

    (new DownloadTransferSingleStream($transfer->id, 'image/jpeg'))->handle(
        app(FileDownloadFinalizer::class),
        app(DownloadTransferProgressBroadcaster::class),
        app(\App\Services\Downloads\DownloadTransferRequestOptions::class),
    );

    Http::assertSent(function (Request $request): bool {
        return $request->header('Referer')[0] === 'https://www.example.test/post/123'
            && $request->header('User-Agent')[0] === 'AtlasExtensionRuntime/1.0'
            && $request->header('Cookie')[0] === 'auth=abc';
    });
});
