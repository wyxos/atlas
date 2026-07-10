<?php

use App\Enums\DownloadChunkStatus;
use App\Enums\DownloadTransferStatus;
use App\Jobs\Downloads\AssembleDownloadTransfer;
use App\Jobs\Downloads\DownloadTransferChunk;
use App\Jobs\Downloads\DownloadTransferSingleStream;
use App\Jobs\Downloads\DownloadTransferYtDlp;
use App\Jobs\Downloads\PrepareDownloadTransfer;
use App\Models\DownloadChunk;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Services\Downloads\DownloadTransferProgressBroadcaster;
use App\Services\Downloads\DownloadTransferRequestOptions;
use App\Services\Downloads\FileDownloadFinalizer;
use App\Services\Downloads\YtDlpUnsupportedUrlFallback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function makeMediaValidationFallbackTransfer(array $transferAttributes = []): array
{
    $assetUrl = 'https://assets.example.test/media.mp4';
    $file = File::factory()->create([
        'source' => 'Extension',
        'url' => 'https://page.example.test/watch/1',
        'preview_url' => $assetUrl,
        'downloaded' => false,
        'path' => null,
        'filename' => 'media.mp4',
        'ext' => 'mp4',
        'listing_metadata' => [
            'download_via_reason' => YtDlpUnsupportedUrlFallback::REASON,
            'extension_channel' => 'atlas-extension',
            'tag_name' => 'video',
        ],
    ]);

    $transfer = DownloadTransfer::query()->create(array_merge([
        'file_id' => $file->id,
        'url' => $assetUrl,
        'domain' => 'assets.example.test',
        'status' => DownloadTransferStatus::DOWNLOADING,
        'attempt' => 0,
        'bytes_total' => null,
        'bytes_downloaded' => 0,
        'last_broadcast_percent' => 0,
    ], $transferAttributes));

    return [$file, $transfer];
}

it('rejects an HTML range probe before dispatching a native download', function () {
    Bus::fake();
    [, $transfer] = makeMediaValidationFallbackTransfer(['status' => DownloadTransferStatus::QUEUED]);

    Http::fake(function (Request $request) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 200, ['Content-Type' => 'video/mp4']);
        }

        return Http::response('<html>blocked</html>', 206, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Range' => 'bytes 0-0/20000000',
        ]);
    });

    (new PrepareDownloadTransfer($transfer->id, 0))->handle();

    expect($transfer->fresh()->status)->toBe(DownloadTransferStatus::FAILED)
        ->and($transfer->fresh()->error)->toBe('Native fallback returned an HTML page instead of media.');
    Bus::assertNotDispatched(DownloadTransferSingleStream::class);
    Bus::assertNotDispatched(DownloadTransferYtDlp::class);
    Bus::assertBatchCount(0);
});

it('rejects an HTML artifact with a misleading media content type', function () {
    Bus::fake();
    Storage::fake('atlas');
    [$file, $transfer] = makeMediaValidationFallbackTransfer();
    $body = "\xEF\xBB\xBF  <!doctype html><html><body>blocked</body></html>";

    Http::fake(['*' => Http::response($body, 200, [
        'Content-Type' => 'video/mp4',
        'Content-Length' => (string) strlen($body),
    ])]);

    (new DownloadTransferSingleStream($transfer->id, 'video/mp4', 0))->handle(
        app(FileDownloadFinalizer::class),
        app(DownloadTransferProgressBroadcaster::class),
        app(DownloadTransferRequestOptions::class),
    );

    expect($transfer->fresh()->status)->toBe(DownloadTransferStatus::FAILED)
        ->and($transfer->fresh()->error)->toBe('Native fallback returned an HTML page instead of media.')
        ->and($file->fresh()->downloaded)->toBeFalse();
});

it('rejects an empty native fallback artifact before finalization', function () {
    Bus::fake();
    Storage::fake('atlas');
    [$file, $transfer] = makeMediaValidationFallbackTransfer();
    Http::fake(['*' => Http::response('', 200, [
        'Content-Type' => 'video/mp4',
        'Content-Length' => '0',
    ])]);

    (new DownloadTransferSingleStream($transfer->id, 'video/mp4', 0))->handle(
        app(FileDownloadFinalizer::class),
        app(DownloadTransferProgressBroadcaster::class),
        app(DownloadTransferRequestOptions::class),
    );

    expect($transfer->fresh()->status)->toBe(DownloadTransferStatus::FAILED)
        ->and($transfer->fresh()->error)->toBe('Unable to validate the native fallback response.')
        ->and($file->fresh()->downloaded)->toBeFalse();
});

it('rejects an HTML chunk before it can enter assembly', function () {
    Bus::fake();
    Storage::fake('atlas');
    [$file, $transfer] = makeMediaValidationFallbackTransfer();
    $body = '<html>blocked response</html>';
    $partPath = "downloads/.tmp/transfer-{$transfer->id}/part-0.part";
    $chunk = DownloadChunk::query()->create([
        'download_transfer_id' => $transfer->id,
        'index' => 0,
        'range_start' => 0,
        'range_end' => strlen($body) - 1,
        'bytes_downloaded' => 0,
        'status' => DownloadChunkStatus::PENDING,
        'part_path' => $partPath,
    ]);

    Http::fake(['*' => Http::response($body, 206, ['Content-Type' => 'text/html'])]);

    (new DownloadTransferChunk($transfer->id, $chunk->id, 'video/mp4', 0))->handle(
        app(DownloadTransferProgressBroadcaster::class),
        app(DownloadTransferRequestOptions::class),
    );

    expect($transfer->fresh()->status)->toBe(DownloadTransferStatus::FAILED)
        ->and($transfer->fresh()->error)->toBe('Native fallback returned an HTML page instead of media.')
        ->and($chunk->fresh()->status)->toBe(DownloadChunkStatus::FAILED)
        ->and($file->fresh()->downloaded)->toBeFalse();
});

it('rejects an assembled HTML artifact before finalization', function () {
    Bus::fake();
    Storage::fake('atlas');
    [$file, $transfer] = makeMediaValidationFallbackTransfer(['bytes_total' => 20]);
    $partPath = "downloads/.tmp/transfer-{$transfer->id}/part-0.part";
    Storage::disk('atlas')->put($partPath, '<html>blocked</html>');
    DownloadChunk::query()->create([
        'download_transfer_id' => $transfer->id,
        'index' => 0,
        'range_start' => 0,
        'range_end' => 19,
        'bytes_downloaded' => 20,
        'status' => DownloadChunkStatus::COMPLETED,
        'part_path' => $partPath,
    ]);

    (new AssembleDownloadTransfer($transfer->id, 'video/mp4', 0))->handle(app(FileDownloadFinalizer::class));

    expect($transfer->fresh()->status)->toBe(DownloadTransferStatus::FAILED)
        ->and($transfer->fresh()->error)->toBe('Native fallback returned an HTML page instead of media.')
        ->and($file->fresh()->downloaded)->toBeFalse();
});
