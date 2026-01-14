<?php

use App\Enums\DownloadChunkStatus;
use App\Enums\DownloadTransferStatus;
use App\Events\DownloadTransferProgressUpdated;
use App\Jobs\Downloads\AssembleDownloadTransfer;
use App\Jobs\Downloads\PumpDomainDownloads;
use App\Jobs\Downloads\QueueDownloadTransfer;
use App\Models\DownloadChunk;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Services\Downloads\DownloadTransferProgressBroadcaster;
use App\Services\Downloads\FileDownloadFinalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('pumps at most 20 transfers per domain (queued includes waiting+active)', function () {
    Bus::fake();

    $file = File::factory()->create([
        'url' => 'https://example.com/test.bin',
        'filename' => 'test.bin',
        'ext' => 'bin',
        'downloaded' => false,
    ]);

    for ($i = 0; $i < 21; $i++) {
        DownloadTransfer::query()->create([
            'file_id' => $file->id,
            'url' => 'https://example.com/test.bin',
            'domain' => 'example.com',
            'status' => DownloadTransferStatus::PENDING,
            'bytes_total' => null,
            'bytes_downloaded' => 0,
            'last_broadcast_percent' => 0,
        ]);
    }

    (new PumpDomainDownloads('example.com'))->handle();

    expect(DownloadTransfer::query()->where('domain', 'example.com')->where('status', DownloadTransferStatus::QUEUED)->count())->toBe(20);
    expect(DownloadTransfer::query()->where('domain', 'example.com')->where('status', DownloadTransferStatus::PENDING)->count())->toBe(1);

    Bus::assertDispatched(QueueDownloadTransfer::class, 20);
});

test('pumps respects global transfer cap across domains', function () {
    Bus::fake();

    config()->set('downloads.max_transfers_total', 20);
    config()->set('downloads.max_transfers_per_domain', 20);

    $exampleFile = File::factory()->create([
        'url' => 'https://example.com/test.bin',
        'filename' => 'test.bin',
        'ext' => 'bin',
        'downloaded' => false,
    ]);

    $otherFile = File::factory()->create([
        'url' => 'https://other.com/test.bin',
        'filename' => 'test.bin',
        'ext' => 'bin',
        'downloaded' => false,
    ]);

    for ($i = 0; $i < 5; $i++) {
        DownloadTransfer::query()->create([
            'file_id' => $otherFile->id,
            'url' => 'https://other.com/test.bin',
            'domain' => 'other.com',
            'status' => DownloadTransferStatus::DOWNLOADING,
            'bytes_total' => 100,
            'bytes_downloaded' => 20,
            'last_broadcast_percent' => 0,
        ]);
    }

    for ($i = 0; $i < 20; $i++) {
        DownloadTransfer::query()->create([
            'file_id' => $exampleFile->id,
            'url' => 'https://example.com/test.bin',
            'domain' => 'example.com',
            'status' => DownloadTransferStatus::PENDING,
            'bytes_total' => null,
            'bytes_downloaded' => 0,
            'last_broadcast_percent' => 0,
        ]);
    }

    (new PumpDomainDownloads('example.com'))->handle();

    expect(DownloadTransfer::query()->where('domain', 'example.com')->where('status', DownloadTransferStatus::QUEUED)->count())->toBe(15);
    expect(DownloadTransfer::query()->where('domain', 'example.com')->where('status', DownloadTransferStatus::PENDING)->count())->toBe(5);

    Bus::assertDispatched(QueueDownloadTransfer::class, 15);
});

test('progress broadcaster emits only at 5% boundaries and updates File.download_progress', function () {
    Event::fake([DownloadTransferProgressUpdated::class]);

    $file = File::factory()->create([
        'url' => 'https://example.com/test.bin',
        'filename' => 'test.bin',
        'ext' => 'bin',
        'downloaded' => false,
        'download_progress' => 0,
    ]);

    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => 'https://example.com/test.bin',
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::DOWNLOADING,
        'bytes_total' => 100,
        'bytes_downloaded' => 4,
        'last_broadcast_percent' => 0,
    ]);

    app(DownloadTransferProgressBroadcaster::class)->maybeBroadcast($transfer->id);
    Event::assertNotDispatched(DownloadTransferProgressUpdated::class);

    $transfer->update(['bytes_downloaded' => 5]);
    app(DownloadTransferProgressBroadcaster::class)->maybeBroadcast($transfer->id);

    $file->refresh();
    expect($file->download_progress)->toBe(5);

    Event::assertDispatched(DownloadTransferProgressUpdated::class, function (DownloadTransferProgressUpdated $event) use ($transfer) {
        return $event->downloadTransferId === $transfer->id && $event->percent === 5;
    });

    Event::fake([DownloadTransferProgressUpdated::class]);
    $transfer->update(['bytes_downloaded' => 9]);
    app(DownloadTransferProgressBroadcaster::class)->maybeBroadcast($transfer->id);
    Event::assertNotDispatched(DownloadTransferProgressUpdated::class);

    $transfer->update(['bytes_downloaded' => 10]);
    app(DownloadTransferProgressBroadcaster::class)->maybeBroadcast($transfer->id);
    Event::assertDispatched(DownloadTransferProgressUpdated::class, fn (DownloadTransferProgressUpdated $event) => $event->percent === 10);
});

test('assemble job concatenates chunk parts, finalizes file, and marks transfer completed', function () {
    Bus::fake();
    Storage::fake('atlas-app');
    Event::fake([DownloadTransferProgressUpdated::class]);

    $file = File::factory()->create([
        'url' => 'https://example.com/test.bin',
        'filename' => 'test.bin',
        'ext' => 'bin',
        'downloaded' => false,
        'path' => null,
    ]);

    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => 'https://example.com/test.bin',
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::DOWNLOADING,
        'bytes_total' => 6,
        'bytes_downloaded' => 6,
        'last_broadcast_percent' => 95,
    ]);

    $tmpDir = 'downloads/.tmp/transfer-'.$transfer->id;
    $part0 = "{$tmpDir}/part-0.part";
    $part1 = "{$tmpDir}/part-1.part";

    Storage::disk('atlas-app')->put($part0, 'abc');
    Storage::disk('atlas-app')->put($part1, 'def');

    DownloadChunk::query()->create([
        'download_transfer_id' => $transfer->id,
        'index' => 0,
        'range_start' => 0,
        'range_end' => 2,
        'status' => DownloadChunkStatus::COMPLETED,
        'bytes_downloaded' => 3,
        'part_path' => $part0,
    ]);
    DownloadChunk::query()->create([
        'download_transfer_id' => $transfer->id,
        'index' => 1,
        'range_start' => 3,
        'range_end' => 5,
        'status' => DownloadChunkStatus::COMPLETED,
        'bytes_downloaded' => 3,
        'part_path' => $part1,
    ]);

    (new AssembleDownloadTransfer($transfer->id))->handle(app(FileDownloadFinalizer::class));

    $transfer->refresh();
    $file->refresh();

    expect($transfer->status)->toBe(DownloadTransferStatus::COMPLETED);
    expect($file->downloaded)->toBeTrue();
    expect($file->path)->toStartWith('downloads/');
    Storage::disk('atlas-app')->assertExists($file->path);
    expect(Storage::disk('atlas-app')->get($file->path))->toBe('abcdef');

    Event::assertDispatched(DownloadTransferProgressUpdated::class, fn (DownloadTransferProgressUpdated $event) => $event->downloadTransferId === $transfer->id && $event->percent === 100);
});
