<?php

use App\Enums\DownloadChunkStatus;
use App\Enums\DownloadTransferStatus;
use App\Jobs\Downloads\AssembleDownloadTransfer;
use App\Jobs\Downloads\DownloadTransferChunk;
use App\Jobs\Downloads\DownloadTransferSingleStream;
use App\Jobs\Downloads\DownloadTransferYtDlp;
use App\Jobs\Downloads\PrepareDownloadTransfer;
use App\Jobs\Downloads\PumpDomainDownloads;
use App\Jobs\Downloads\QueueDownloadTransfer;
use App\Models\DownloadChunk;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Services\Downloads\DownloadTransferBatchFailureHandler;
use App\Services\Downloads\DownloadTransferProgressBroadcaster;
use App\Services\Downloads\DownloadTransferRequestOptions;
use App\Services\Downloads\DownloadTransferRuntimeStore;
use App\Services\Downloads\FileDownloadFinalizer;
use App\Services\Downloads\YtDlpUnsupportedUrlFallback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function makeGenerationFallbackTransfer(array $transferAttributes = []): array
{
    $assetUrl = 'https://assets.example.test/media.mp4';
    $file = File::factory()->create([
        'source' => 'Extension',
        'url' => 'https://page.example.test/watch/1',
        'preview_url' => $assetUrl,
        'downloaded' => false,
        'path' => null,
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
        'attempt' => 1,
        'bytes_total' => null,
        'bytes_downloaded' => 0,
        'last_broadcast_percent' => 0,
    ], $transferAttributes));

    return [$file, $transfer];
}

it('propagates the current generation when pumping a transfer', function () {
    Bus::fake([QueueDownloadTransfer::class]);

    [, $transfer] = makeGenerationFallbackTransfer([
        'status' => DownloadTransferStatus::PENDING,
        'attempt' => 4,
    ]);

    (new PumpDomainDownloads($transfer->domain))->handle();

    Bus::assertDispatched(QueueDownloadTransfer::class, fn (QueueDownloadTransfer $job): bool => property_exists($job, 'attempt') && $job->attempt === 4
    );
});

it('does not let a stale prepare generation issue a request', function () {
    [, $transfer] = makeGenerationFallbackTransfer([
        'status' => DownloadTransferStatus::QUEUED,
        'attempt' => 2,
    ]);

    Http::fake();

    (new PrepareDownloadTransfer($transfer->id, 1))->handle();

    Http::assertNothingSent();
    expect($transfer->fresh()->status)->toBe(DownloadTransferStatus::QUEUED);
});

it('does not let a stale queue generation dispatch preparation', function () {
    Bus::fake([PrepareDownloadTransfer::class]);
    [, $transfer] = makeGenerationFallbackTransfer([
        'status' => DownloadTransferStatus::QUEUED,
        'attempt' => 2,
    ]);

    (new QueueDownloadTransfer($transfer->id, 1))->handle();

    Bus::assertNotDispatched(PrepareDownloadTransfer::class);
});

it('lets a pre-deploy queue job adopt the current orchestration generation', function () {
    Bus::fake([PrepareDownloadTransfer::class]);
    [, $transfer] = makeGenerationFallbackTransfer([
        'status' => DownloadTransferStatus::QUEUED,
        'attempt' => 4,
    ]);

    (new QueueDownloadTransfer($transfer->id, null))->handle();

    Bus::assertDispatched(PrepareDownloadTransfer::class, fn (PrepareDownloadTransfer $job): bool => $job->attempt === 4);
});

it('deserializes a pre-deploy queue payload without an attempt property', function () {
    Bus::fake([PrepareDownloadTransfer::class]);
    [, $transfer] = makeGenerationFallbackTransfer([
        'status' => DownloadTransferStatus::QUEUED,
        'attempt' => 4,
    ]);
    $class = QueueDownloadTransfer::class;
    $payload = sprintf(
        'O:%d:"%s":1:{s:18:"downloadTransferId";i:%d;}',
        strlen($class),
        $class,
        $transfer->id,
    );
    $job = unserialize($payload, ['allowed_classes' => [$class]]);

    expect($job)->toBeInstanceOf(QueueDownloadTransfer::class)
        ->and($job->attempt)->toBeNull();
    $job->handle();

    Bus::assertDispatched(PrepareDownloadTransfer::class, fn (PrepareDownloadTransfer $queued): bool => $queued->attempt === 4);
});

it('lets a pre-deploy prepare job adopt an existing yt-dlp generation', function () {
    Bus::fake([DownloadTransferYtDlp::class]);
    [$file, $transfer] = makeGenerationFallbackTransfer([
        'status' => DownloadTransferStatus::QUEUED,
        'attempt' => 4,
    ]);
    $file->update(['listing_metadata' => ['download_via' => 'yt-dlp', 'tag_name' => 'video']]);

    (new PrepareDownloadTransfer($transfer->id, null))->handle();

    Bus::assertDispatched(DownloadTransferYtDlp::class, fn (DownloadTransferYtDlp $job): bool => $job->attempt === 4);
});

it('does not let a pre-deploy native worker adopt an advanced generation', function () {
    [, $transfer] = makeGenerationFallbackTransfer(['attempt' => 2]);
    Http::fake();

    (new DownloadTransferSingleStream($transfer->id, 'video/mp4', null))->handle(
        app(FileDownloadFinalizer::class),
        app(DownloadTransferProgressBroadcaster::class),
        app(DownloadTransferRequestOptions::class),
    );

    Http::assertNothingSent();
    expect($transfer->fresh()->status)->toBe(DownloadTransferStatus::DOWNLOADING);
});

it('does not let a superseded HEAD response fail the replacement generation', function () {
    Bus::fake();
    [, $transfer] = makeGenerationFallbackTransfer([
        'status' => DownloadTransferStatus::QUEUED,
        'attempt' => 1,
    ]);

    Http::fake(function () use ($transfer) {
        DownloadTransfer::query()->whereKey($transfer->id)->update([
            'attempt' => 2,
            'status' => DownloadTransferStatus::QUEUED,
        ]);

        return Http::response('', 200, ['Content-Type' => 'text/html']);
    });

    (new PrepareDownloadTransfer($transfer->id, 1))->handle();

    expect($transfer->fresh()->attempt)->toBe(2)
        ->and($transfer->fresh()->status)->toBe(DownloadTransferStatus::QUEUED)
        ->and($transfer->fresh()->error)->toBeNull();
});

it('does not let an old single stream response finalize or clear a restarted generation', function () {
    Bus::fake();
    Storage::fake('atlas');

    [$file, $transfer] = makeGenerationFallbackTransfer();
    $runtimeStore = app(DownloadTransferRuntimeStore::class);

    Http::fake(function (Request $request) use ($transfer, $runtimeStore) {
        DownloadTransfer::query()->whereKey($transfer->id)->update(['attempt' => 2]);
        $runtimeStore->putForTransfer($transfer->id, ['user_agent' => 'replacement-agent']);

        return Http::response('media', 200, [
            'Content-Type' => 'video/mp4',
            'Content-Length' => '5',
        ]);
    });

    (new DownloadTransferSingleStream($transfer->id, 'video/mp4', 1))->handle(
        app(FileDownloadFinalizer::class),
        app(DownloadTransferProgressBroadcaster::class),
        app(DownloadTransferRequestOptions::class),
    );

    expect($transfer->fresh()->status)->toBe(DownloadTransferStatus::DOWNLOADING)
        ->and($transfer->fresh()->attempt)->toBe(2)
        ->and($file->fresh()->downloaded)->toBeFalse()
        ->and($runtimeStore->getForTransfer($transfer->id)['user_agent'] ?? null)->toBe('replacement-agent');
});

it('does not let an old chunk response write into a restarted generation', function () {
    Storage::fake('atlas');

    [, $transfer] = makeGenerationFallbackTransfer(['bytes_total' => 5]);
    $partPath = "downloads/.tmp/transfer-{$transfer->id}-attempt-1/part-0.part";
    $chunk = DownloadChunk::query()->create([
        'download_transfer_id' => $transfer->id,
        'index' => 0,
        'range_start' => 0,
        'range_end' => 4,
        'bytes_downloaded' => 0,
        'status' => DownloadChunkStatus::PENDING,
        'part_path' => $partPath,
    ]);

    Http::fake(function () use ($transfer, $chunk) {
        DownloadTransfer::query()->whereKey($transfer->id)->update(['attempt' => 2]);
        DownloadChunk::query()->whereKey($chunk->id)->delete();

        return Http::response('media', 206, ['Content-Type' => 'video/mp4']);
    });

    (new DownloadTransferChunk($transfer->id, $chunk->id, 'video/mp4', 1))->handle(
        app(DownloadTransferProgressBroadcaster::class),
        app(DownloadTransferRequestOptions::class),
    );

    Storage::disk('atlas')->assertMissing($partPath);
    expect($transfer->fresh()->attempt)->toBe(2)
        ->and($transfer->fresh()->status)->toBe(DownloadTransferStatus::DOWNLOADING);
});

it('does not let a stale assembly generation finalize old fragments', function () {
    Bus::fake();
    Storage::fake('atlas');

    [$file, $transfer] = makeGenerationFallbackTransfer(['attempt' => 2, 'bytes_total' => 5]);
    $oldPath = "downloads/.tmp/transfer-{$transfer->id}-attempt-1/part-0.part";
    Storage::disk('atlas')->put($oldPath, 'media');
    DownloadChunk::query()->create([
        'download_transfer_id' => $transfer->id,
        'index' => 0,
        'range_start' => 0,
        'range_end' => 4,
        'bytes_downloaded' => 5,
        'status' => DownloadChunkStatus::COMPLETED,
        'part_path' => $oldPath,
    ]);

    (new AssembleDownloadTransfer($transfer->id, 'video/mp4', 1))->handle(app(FileDownloadFinalizer::class));

    expect($transfer->fresh()->status)->toBe(DownloadTransferStatus::DOWNLOADING)
        ->and($file->fresh()->downloaded)->toBeFalse();
});

it('does not let stale progress update the replacement transfer or file', function () {
    [$file, $transfer] = makeGenerationFallbackTransfer([
        'attempt' => 2,
        'bytes_total' => 100,
        'bytes_downloaded' => 80,
        'last_broadcast_percent' => 10,
    ]);
    $file->update(['download_progress' => 10]);

    app(DownloadTransferProgressBroadcaster::class)->maybeBroadcast($transfer->id, 1);

    expect($transfer->fresh()->last_broadcast_percent)->toBe(10)
        ->and($file->fresh()->download_progress)->toBe(10);
});

it('does not let a stale chunk batch failure mutate replacement state or runtime context', function () {
    Bus::fake();
    [, $transfer] = makeGenerationFallbackTransfer(['attempt' => 2]);
    $chunk = DownloadChunk::query()->create([
        'download_transfer_id' => $transfer->id,
        'index' => 0,
        'range_start' => 0,
        'range_end' => 4,
        'bytes_downloaded' => 0,
        'status' => DownloadChunkStatus::PENDING,
        'part_path' => 'unused.part',
    ]);
    $runtimeStore = app(DownloadTransferRuntimeStore::class);
    $runtimeStore->putForTransfer($transfer->id, ['user_agent' => 'replacement-agent']);

    app(DownloadTransferBatchFailureHandler::class)->handle(
        $transfer->id,
        1,
        $transfer->domain,
        [$chunk->id],
        new RuntimeException('Old batch failed at https://private.example.test/secret-value'),
    );

    expect($transfer->fresh()->status)->toBe(DownloadTransferStatus::DOWNLOADING)
        ->and($chunk->fresh()->status)->toBe(DownloadChunkStatus::PENDING)
        ->and($runtimeStore->getForTransfer($transfer->id)['user_agent'] ?? null)->toBe('replacement-agent');
});
