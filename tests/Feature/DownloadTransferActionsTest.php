<?php

use App\Enums\DownloadChunkStatus;
use App\Enums\DownloadTransferStatus;
use App\Jobs\Downloads\PumpDomainDownloads;
use App\Models\DownloadChunk;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('pauses an active transfer', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'url' => 'https://example.com/test.bin',
    ]);
    File::query()->whereKey($file->id)->update(['download_progress' => 10]);
    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => 'https://example.com/test.bin',
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::DOWNLOADING,
        'bytes_total' => 100,
        'bytes_downloaded' => 10,
        'last_broadcast_percent' => 10,
    ]);

    $response = $this->actingAs($user)->postJson("/api/download-transfers/{$transfer->id}/pause");

    $response->assertSuccessful();
    $transfer->refresh();
    expect($transfer->status)->toBe(DownloadTransferStatus::PAUSED);
});

it('resumes a paused transfer and requeues it', function () {
    Bus::fake();

    $user = User::factory()->create();
    $file = File::factory()->create([
        'url' => 'https://example.com/test.bin',
    ]);
    File::query()->whereKey($file->id)->update(['download_progress' => 55]);
    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => 'https://example.com/test.bin',
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::PAUSED,
        'bytes_total' => 100,
        'bytes_downloaded' => 55,
        'last_broadcast_percent' => 55,
    ]);
    DownloadChunk::query()->create([
        'download_transfer_id' => $transfer->id,
        'index' => 0,
        'range_start' => 0,
        'range_end' => 10,
        'bytes_downloaded' => 11,
        'status' => DownloadChunkStatus::DOWNLOADING,
        'part_path' => 'downloads/.tmp/transfer-'.$transfer->id.'/part-0.part',
    ]);

    $response = $this->actingAs($user)->postJson("/api/download-transfers/{$transfer->id}/resume");

    $response->assertSuccessful();

    $transfer->refresh();
    $file->refresh();

    expect($transfer->status)->toBe(DownloadTransferStatus::PENDING);
    expect($transfer->bytes_total)->toBeNull();
    expect($transfer->bytes_downloaded)->toBe(0);
    expect($transfer->last_broadcast_percent)->toBe(0);
    expect($file->download_progress)->toBe(0);
    expect(DownloadChunk::query()->where('download_transfer_id', $transfer->id)->count())->toBe(0);

    Bus::assertDispatched(PumpDomainDownloads::class, fn (PumpDomainDownloads $job) => $job->domain === 'example.com');
});

it('cancels an active transfer and clears progress', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'url' => 'https://example.com/test.bin',
    ]);
    File::query()->whereKey($file->id)->update(['download_progress' => 35]);
    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => 'https://example.com/test.bin',
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::DOWNLOADING,
        'bytes_total' => 100,
        'bytes_downloaded' => 35,
        'last_broadcast_percent' => 35,
    ]);
    DownloadChunk::query()->create([
        'download_transfer_id' => $transfer->id,
        'index' => 0,
        'range_start' => 0,
        'range_end' => 10,
        'bytes_downloaded' => 11,
        'status' => DownloadChunkStatus::DOWNLOADING,
    ]);

    $response = $this->actingAs($user)->postJson("/api/download-transfers/{$transfer->id}/cancel");

    $response->assertSuccessful();

    $transfer->refresh();
    $file->refresh();

    expect($transfer->status)->toBe(DownloadTransferStatus::CANCELED);
    expect($file->download_progress)->toBe(0);
    expect(DownloadChunk::query()->where('download_transfer_id', $transfer->id)->count())->toBe(0);
});

it('restarts a canceled transfer', function () {
    Bus::fake();

    $user = User::factory()->create();
    $file = File::factory()->create([
        'url' => 'https://example.com/test.bin',
    ]);
    File::query()->whereKey($file->id)->update(['download_progress' => 40]);
    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => 'https://example.com/test.bin',
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::CANCELED,
        'bytes_total' => 100,
        'bytes_downloaded' => 40,
        'last_broadcast_percent' => 40,
    ]);

    $response = $this->actingAs($user)->postJson("/api/download-transfers/{$transfer->id}/restart");

    $response->assertSuccessful();

    $transfer->refresh();
    $file->refresh();

    expect($transfer->status)->toBe(DownloadTransferStatus::PENDING);
    expect($transfer->bytes_total)->toBeNull();
    expect($transfer->bytes_downloaded)->toBe(0);
    expect($transfer->last_broadcast_percent)->toBe(0);
    expect($file->download_progress)->toBe(0);

    Bus::assertDispatched(PumpDomainDownloads::class, fn (PumpDomainDownloads $job) => $job->domain === 'example.com');
});

it('removes a transfer and deletes the file from disk', function () {
    Storage::fake('atlas-app');

    $user = User::factory()->create();
    $file = File::factory()->create([
        'url' => 'https://example.com/test.jpg',
        'filename' => 'test.jpg',
        'downloaded' => true,
        'path' => 'downloads/aa/bb/test.jpg',
        'preview_path' => 'thumbnails/aa/bb/test_thumb.jpg',
    ]);
    File::query()->whereKey($file->id)->update(['download_progress' => 100]);
    Storage::disk('atlas-app')->put($file->path, 'file');
    Storage::disk('atlas-app')->put($file->preview_path, 'thumb');

    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => 'https://example.com/test.jpg',
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::COMPLETED,
        'bytes_total' => 100,
        'bytes_downloaded' => 100,
        'last_broadcast_percent' => 100,
    ]);

    $response = $this->actingAs($user)->deleteJson("/api/download-transfers/{$transfer->id}/disk");

    $response->assertSuccessful();

    expect(DownloadTransfer::query()->whereKey($transfer->id)->exists())->toBeFalse();

    $file->refresh();
    expect($file->path)->toBeNull();
    expect($file->preview_path)->toBeNull();
    expect($file->downloaded)->toBeFalse();
    expect($file->download_progress)->toBe(0);

    Storage::disk('atlas-app')->assertMissing('downloads/aa/bb/test.jpg');
    Storage::disk('atlas-app')->assertMissing('thumbnails/aa/bb/test_thumb.jpg');
});
