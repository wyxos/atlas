<?php

use App\Enums\DownloadTransferStatus;
use App\Jobs\Downloads\DownloadTransferYtDlp;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Services\Downloads\DownloadTransferRequestOptions;
use App\Services\Downloads\FileDownloadFinalizer;
use App\Services\Downloads\YtDlpCommandBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

it('ignores stale yt-dlp jobs once the transfer attempt has advanced', function () {
    $file = File::factory()->create([
        'source' => 'Extension',
        'url' => 'https://example.com/watch?v=stale-attempt',
        'referrer_url' => 'https://example.com/watch?v=stale-attempt',
        'downloaded' => false,
        'path' => null,
        'listing_metadata' => ['download_via' => 'yt-dlp', 'tag_name' => 'video'],
    ]);

    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $file->url,
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::DOWNLOADING,
        'attempt' => 1,
        'bytes_total' => null,
        'bytes_downloaded' => 0,
        'last_broadcast_percent' => 0,
    ]);

    $this->mock(YtDlpCommandBuilder::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('build');
    });

    (new DownloadTransferYtDlp($transfer->id, 0))->handle(
        app(FileDownloadFinalizer::class),
        app(YtDlpCommandBuilder::class),
        app(DownloadTransferRequestOptions::class),
    );

    $transfer->refresh();

    expect($transfer->status)->toBe(DownloadTransferStatus::DOWNLOADING)
        ->and($transfer->attempt)->toBe(1);
});
