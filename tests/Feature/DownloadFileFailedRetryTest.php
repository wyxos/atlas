<?php

use App\Enums\DownloadChunkStatus;
use App\Enums\DownloadTransferStatus;
use App\Events\DownloadTransferProgressUpdated;
use App\Jobs\DownloadFile;
use App\Jobs\Downloads\PumpDomainDownloads;
use App\Models\DownloadChunk;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Services\Downloads\DownloadTransferRuntimeStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('atlas');
    Cache::flush();
});

test('retries a matching failed transfer instead of creating a duplicate', function () {
    Bus::fake();
    Event::fake([DownloadTransferProgressUpdated::class]);

    $file = File::factory()->create([
        'url' => 'https://example.com/retry-from-extension.jpg',
        'downloaded' => false,
        'path' => null,
        'download_progress' => 73,
    ]);

    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => 'https://example.com/retry-from-extension.jpg',
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::FAILED,
        'bytes_total' => 100,
        'bytes_downloaded' => 73,
        'last_broadcast_percent' => 73,
        'batch_id' => 'old-batch',
        'queued_at' => now()->subMinutes(4),
        'started_at' => now()->subMinutes(3),
        'finished_at' => now()->subMinute(),
        'failed_at' => now()->subMinute(),
        'error' => 'Expired extension cookies.',
    ]);

    $tmpDir = 'downloads/.tmp/transfer-'.$transfer->id;
    Storage::disk('atlas')->put($tmpDir.'/part-0.part', 'partial');
    DownloadChunk::query()->create([
        'download_transfer_id' => $transfer->id,
        'index' => 0,
        'range_start' => 0,
        'range_end' => 99,
        'bytes_downloaded' => 73,
        'status' => DownloadChunkStatus::FAILED,
        'part_path' => $tmpDir.'/part-0.part',
        'failed_at' => now()->subMinute(),
        'error' => 'Expired extension cookies.',
    ]);

    app(DownloadTransferRuntimeStore::class)->putForTransfer($transfer->id, [
        'user_agent' => 'AtlasExtensionRuntime/old',
    ]);

    (new DownloadFile($file->id, false, [
        'user_agent' => 'AtlasExtensionRuntime/new',
        'user_id' => 123,
    ]))->handle();

    $transfer->refresh();
    $file->refresh();
    $runtimeContext = app(DownloadTransferRuntimeStore::class)->getForTransfer($transfer->id);

    expect(DownloadTransfer::query()->where('file_id', $file->id)->count())->toBe(1)
        ->and($transfer->status)->toBe(DownloadTransferStatus::PENDING)
        ->and($transfer->bytes_total)->toBeNull()
        ->and($transfer->bytes_downloaded)->toBe(0)
        ->and($transfer->last_broadcast_percent)->toBe(0)
        ->and($transfer->batch_id)->toBeNull()
        ->and($transfer->queued_at)->toBeNull()
        ->and($transfer->started_at)->toBeNull()
        ->and($transfer->finished_at)->toBeNull()
        ->and($transfer->failed_at)->toBeNull()
        ->and($transfer->error)->toBeNull()
        ->and($file->download_progress)->toBe(0)
        ->and(DownloadChunk::query()->where('download_transfer_id', $transfer->id)->count())->toBe(0)
        ->and(Storage::disk('atlas')->exists($tmpDir))->toBeFalse()
        ->and($runtimeContext['user_agent'] ?? null)->toBe('AtlasExtensionRuntime/new');

    Bus::assertDispatched(PumpDomainDownloads::class, fn (PumpDomainDownloads $job) => $job->domain === 'example.com');
    Event::assertDispatched(DownloadTransferProgressUpdated::class, fn (DownloadTransferProgressUpdated $event) => $event->downloadTransferId === $transfer->id);
});

test('a stale failed-transfer reset cannot reclaim an active replacement generation', function () {
    $file = File::factory()->create([
        'url' => 'https://example.test/file.bin',
        'downloaded' => false,
        'path' => null,
    ]);
    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $file->url,
        'domain' => 'example.test',
        'status' => DownloadTransferStatus::FAILED,
        'attempt' => 0,
    ]);
    $stale = $transfer->replicate()->setAttribute('id', $transfer->id);
    $method = new ReflectionMethod(DownloadFile::class, 'resetFailedTransferForRetry');
    $job = new DownloadFile($file->id);

    $claimed = $method->invoke($job, $transfer, $file, $file->url, 'example.test', null);
    $lost = $method->invoke($job, $stale, $file, $file->url, 'example.test', null);

    expect($claimed)->toBeInstanceOf(DownloadTransfer::class)
        ->and($lost)->toBeNull()
        ->and($transfer->fresh()->status)->toBe(DownloadTransferStatus::PENDING)
        ->and($transfer->fresh()->attempt)->toBe(1);
});
