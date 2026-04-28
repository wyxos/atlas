<?php

use App\Enums\DownloadChunkStatus;
use App\Enums\DownloadTransferStatus;
use App\Jobs\Downloads\RemoveDownloadTransfers;
use App\Models\DownloadChunk;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use App\Services\Downloads\DownloadTransferRemovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('removes a transfer and deletes the file from disk while keeping the Atlas file record by default', function () {
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
    expect(File::query()->whereKey($file->id)->exists())->toBeTrue();

    $file->refresh();
    expect($file->path)->toBeNull();
    expect($file->preview_path)->toBeNull();
    expect($file->downloaded)->toBeFalse();

    Storage::disk('atlas-app')->assertMissing('downloads/aa/bb/test.jpg');
    Storage::disk('atlas-app')->assertMissing('thumbnails/aa/bb/test_thumb.jpg');
});

it('deletes the file record and cascaded transfers when requested while removing a downloaded file from disk', function () {
    Storage::fake('atlas-app');

    $user = User::factory()->create();
    $reactionUser = User::factory()->create();
    $file = File::factory()->create([
        'url' => 'https://example.com/cascade.jpg',
        'filename' => 'cascade.jpg',
        'downloaded' => true,
        'path' => 'downloads/cascade.jpg',
        'preview_path' => 'thumbnails/cascade.jpg',
    ]);
    Reaction::query()->create([
        'file_id' => $file->id,
        'user_id' => $reactionUser->id,
        'type' => 'love',
    ]);
    Storage::disk('atlas-app')->put($file->path, 'file');
    Storage::disk('atlas-app')->put($file->preview_path, 'thumb');

    $firstTransfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $file->url,
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::COMPLETED,
        'bytes_total' => 100,
        'bytes_downloaded' => 100,
        'last_broadcast_percent' => 100,
    ]);
    $secondTransfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $file->url,
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::FAILED,
        'bytes_total' => 100,
        'bytes_downloaded' => 40,
        'last_broadcast_percent' => 40,
        'failed_at' => now(),
    ]);

    $response = $this->actingAs($user)->deleteJson("/api/download-transfers/{$firstTransfer->id}/disk", [
        'also_delete_record' => true,
    ]);

    $response->assertSuccessful();

    expect(collect($response->json('ids'))->sort()->values()->all())->toBe([
        $firstTransfer->id,
        $secondTransfer->id,
    ]);
    expect($response->json('count'))->toBe(2);
    expect(DownloadTransfer::query()->whereKey($firstTransfer->id)->exists())->toBeFalse();
    expect(DownloadTransfer::query()->whereKey($secondTransfer->id)->exists())->toBeFalse();
    expect(File::query()->whereKey($file->id)->exists())->toBeFalse();
    expect(Reaction::query()->where('file_id', $file->id)->exists())->toBeFalse();

    Storage::disk('atlas-app')->assertMissing('downloads/cascade.jpg');
    Storage::disk('atlas-app')->assertMissing('thumbnails/cascade.jpg');
});

it('keeps reactions and the file record when deleting a non-downloaded file from disk by default', function () {
    Storage::fake('atlas-app');

    $user = User::factory()->create();
    $reactionUser = User::factory()->create();
    $file = File::factory()->create([
        'url' => 'https://example.com/pending.jpg',
        'filename' => 'pending.jpg',
        'downloaded' => false,
        'path' => 'downloads/pending.jpg',
        'preview_path' => 'thumbnails/pending.jpg',
    ]);
    Reaction::query()->create([
        'file_id' => $file->id,
        'user_id' => $reactionUser->id,
        'type' => 'like',
    ]);
    Storage::disk('atlas-app')->put($file->path, 'file');
    Storage::disk('atlas-app')->put($file->preview_path, 'thumb');

    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $file->url,
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::FAILED,
        'bytes_total' => 100,
        'bytes_downloaded' => 10,
        'last_broadcast_percent' => 10,
        'failed_at' => now(),
    ]);

    $response = $this->actingAs($user)->deleteJson("/api/download-transfers/{$transfer->id}/disk");

    $response->assertSuccessful()
        ->assertJson([
            'ids' => [$transfer->id],
            'count' => 1,
        ]);

    expect(DownloadTransfer::query()->whereKey($transfer->id)->exists())->toBeFalse();
    expect(Reaction::query()->where('file_id', $file->id)->exists())->toBeTrue();

    $file->refresh();
    expect($file->path)->toBeNull();
    expect($file->preview_path)->toBeNull();
    expect($file->downloaded)->toBeFalse();

    Storage::disk('atlas-app')->assertMissing('downloads/pending.jpg');
    Storage::disk('atlas-app')->assertMissing('thumbnails/pending.jpg');
});

it('removes an active transfer from disk even when the temp directory and stored files are already missing', function () {
    Storage::fake('atlas-app');

    $file = File::factory()->create([
        'url' => 'https://example.com/missing-active.bin',
        'filename' => 'missing-active.bin',
        'downloaded' => false,
        'path' => 'downloads/missing-active.bin',
        'preview_path' => 'thumbnails/missing-active.jpg',
    ]);
    File::query()->whereKey($file->id)->update(['download_progress' => 35]);

    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $file->url,
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
        'range_end' => 34,
        'bytes_downloaded' => 35,
        'status' => DownloadChunkStatus::DOWNLOADING,
    ]);

    $removedIds = app(DownloadTransferRemovalService::class)->remove($transfer, true);

    expect($removedIds)->toBe([$transfer->id]);
    expect(DownloadTransfer::query()->whereKey($transfer->id)->exists())->toBeFalse();
    expect(DownloadChunk::query()->where('download_transfer_id', $transfer->id)->count())->toBe(0);

    $file->refresh();
    expect($file->path)->toBeNull();
    expect($file->preview_path)->toBeNull();
    expect($file->downloaded)->toBeFalse();
    expect($file->download_progress)->toBe(0);
});

it('queues active single-transfer removal instead of blocking request cleanup', function () {
    Bus::fake();

    $user = User::factory()->create();
    $file = File::factory()->create([
        'url' => 'https://example.com/active-remove.bin',
    ]);
    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $file->url,
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::DOWNLOADING,
        'bytes_total' => 100,
        'bytes_downloaded' => 35,
        'last_broadcast_percent' => 35,
    ]);

    $response = $this->actingAs($user)->deleteJson("/api/download-transfers/{$transfer->id}");

    $response->assertSuccessful()
        ->assertJson([
            'message' => 'Download removal queued.',
            'ids' => [$transfer->id],
            'count' => 1,
            'queued' => true,
        ]);

    expect(DownloadTransfer::query()->whereKey($transfer->id)->exists())->toBeTrue();

    Bus::assertDispatched(RemoveDownloadTransfers::class, function (RemoveDownloadTransfers $job) use ($transfer): bool {
        return $job->ids === [$transfer->id]
            && $job->alsoFromDisk === false
            && $job->alsoDeleteRecord === false
            && $job->completedOnly === false;
    });
});

it('queues active single-transfer disk removal instead of blocking request cleanup', function () {
    Bus::fake();

    $user = User::factory()->create();
    $file = File::factory()->create([
        'url' => 'https://example.com/active-remove-disk.bin',
    ]);
    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $file->url,
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::DOWNLOADING,
        'bytes_total' => 100,
        'bytes_downloaded' => 35,
        'last_broadcast_percent' => 35,
    ]);

    $response = $this->actingAs($user)->deleteJson("/api/download-transfers/{$transfer->id}/disk", [
        'also_delete_record' => true,
    ]);

    $response->assertSuccessful()
        ->assertJson([
            'message' => 'Download removal queued.',
            'ids' => [$transfer->id],
            'count' => 1,
            'queued' => true,
        ]);

    expect(DownloadTransfer::query()->whereKey($transfer->id)->exists())->toBeTrue();

    Bus::assertDispatched(RemoveDownloadTransfers::class, function (RemoveDownloadTransfers $job) use ($transfer): bool {
        return $job->ids === [$transfer->id]
            && $job->alsoFromDisk === true
            && $job->alsoDeleteRecord === true
            && $job->completedOnly === false;
    });
});

it('queues active bulk removal requests even below the sync-size threshold', function () {
    Bus::fake();

    config()->set('downloads.bulk_removal_sync_limit', 50);

    $user = User::factory()->create();
    $file = File::factory()->create([
        'url' => 'https://example.com/active-bulk-remove.bin',
    ]);
    $activeTransfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => 'https://example.com/active-bulk-remove.bin',
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::DOWNLOADING,
        'bytes_total' => 100,
        'bytes_downloaded' => 35,
        'last_broadcast_percent' => 35,
    ]);
    $completedTransfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => 'https://example.com/active-bulk-remove-2.bin',
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::COMPLETED,
        'bytes_total' => 100,
        'bytes_downloaded' => 100,
        'last_broadcast_percent' => 100,
    ]);

    $response = $this->actingAs($user)->postJson('/api/download-transfers/bulk-delete', [
        'ids' => [$activeTransfer->id, $completedTransfer->id],
    ]);

    $response->assertSuccessful()
        ->assertJson([
            'message' => 'Download removal queued.',
            'count' => 2,
            'queued' => true,
        ]);

    expect(DownloadTransfer::query()->whereKey($activeTransfer->id)->exists())->toBeTrue();
    expect(DownloadTransfer::query()->whereKey($completedTransfer->id)->exists())->toBeTrue();

    Bus::assertDispatched(RemoveDownloadTransfers::class, function (RemoveDownloadTransfers $job) use ($activeTransfer, $completedTransfer): bool {
        return $job->ids === [$activeTransfer->id, $completedTransfer->id]
            && $job->alsoFromDisk === false
            && $job->alsoDeleteRecord === false
            && $job->completedOnly === false;
    });
});
