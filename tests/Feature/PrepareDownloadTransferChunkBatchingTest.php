<?php

use App\Enums\DownloadTransferStatus;
use App\Jobs\Downloads\DownloadTransferChunk;
use App\Jobs\Downloads\PrepareDownloadTransfer;
use App\Models\DownloadTransfer;
use App\Models\File;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('dispatches chunk batches onto the downloads queue', function () {
    Bus::fake();

    Http::fake(function (Request $request) {
        if ($request->method() !== 'HEAD') {
            throw new RuntimeException('Unexpected HTTP method: '.$request->method());
        }

        return Http::response('', 200, [
            'Content-Type' => 'video/mp4',
            'Content-Length' => (string) (20 * 1024 * 1024),
            'Accept-Ranges' => 'bytes',
        ]);
    });

    $file = File::factory()->create([
        'source' => 'Extension',
        'url' => 'https://example.com/video.mp4',
        'referrer_url' => 'https://example.com/view/video',
        'downloaded' => false,
        'path' => null,
        'listing_metadata' => ['tag_name' => 'video'],
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

    (new PrepareDownloadTransfer($transfer->id))->handle();

    $transfer->refresh();

    expect($transfer->status)->toBe(DownloadTransferStatus::DOWNLOADING);

    Bus::assertBatched(function (PendingBatch $batch): bool {
        return $batch->queue() === 'downloads'
            && $batch->connection() === config('queue.default')
            && $batch->jobs->count() === (int) config('downloads.chunk_count')
            && $batch->jobs->every(fn (mixed $job): bool => $job instanceof DownloadTransferChunk
                && $job->queue === 'downloads'
                && $job->timeout === 600);
    });
});
