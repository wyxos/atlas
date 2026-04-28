<?php

use App\Enums\DownloadTransferStatus;
use App\Events\DownloadTransfersRemoved;
use App\Jobs\Downloads\RemoveDownloadTransfers;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use App\Services\Downloads\DownloadTransferRemovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('removes completed transfers in one request without touching other statuses', function () {
    $user = User::factory()->create();

    $completedFile = File::factory()->create([
        'url' => 'https://example.com/completed-1.bin',
    ]);
    $secondCompletedFile = File::factory()->create([
        'url' => 'https://example.com/completed-2.bin',
    ]);
    $failedFile = File::factory()->create([
        'url' => 'https://example.com/failed.bin',
    ]);

    $completedTransfer = DownloadTransfer::query()->create([
        'file_id' => $completedFile->id,
        'url' => $completedFile->url,
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::COMPLETED,
        'bytes_total' => 100,
        'bytes_downloaded' => 100,
        'last_broadcast_percent' => 100,
    ]);
    $secondCompletedTransfer = DownloadTransfer::query()->create([
        'file_id' => $secondCompletedFile->id,
        'url' => $secondCompletedFile->url,
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::COMPLETED,
        'bytes_total' => 100,
        'bytes_downloaded' => 100,
        'last_broadcast_percent' => 100,
    ]);
    $failedTransfer = DownloadTransfer::query()->create([
        'file_id' => $failedFile->id,
        'url' => $failedFile->url,
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::FAILED,
        'bytes_total' => 100,
        'bytes_downloaded' => 40,
        'last_broadcast_percent' => 40,
    ]);

    $response = $this->actingAs($user)->postJson('/api/download-transfers/bulk-delete-completed');

    $response->assertSuccessful()
        ->assertJson([
            'count' => 2,
        ]);

    expect(DownloadTransfer::query()->whereKey($completedTransfer->id)->exists())->toBeFalse();
    expect(DownloadTransfer::query()->whereKey($secondCompletedTransfer->id)->exists())->toBeFalse();
    expect(DownloadTransfer::query()->whereKey($failedTransfer->id)->exists())->toBeTrue();
});

it('removes completed transfers and deletes their files from disk while keeping Atlas file records by default', function () {
    Storage::fake('atlas-app');

    $user = User::factory()->create();
    $reactionUser = User::factory()->create();
    $file = File::factory()->create([
        'url' => 'https://example.com/completed-video.mp4',
        'filename' => 'completed-video.mp4',
        'downloaded' => true,
        'path' => 'downloads/completed-video.mp4',
        'preview_path' => 'thumbnails/completed-video.jpg',
    ]);
    Reaction::query()->create([
        'file_id' => $file->id,
        'user_id' => $reactionUser->id,
        'type' => 'like',
    ]);
    Storage::disk('atlas-app')->put($file->path, 'video');
    Storage::disk('atlas-app')->put($file->preview_path, 'preview');

    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $file->url,
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::COMPLETED,
        'bytes_total' => 100,
        'bytes_downloaded' => 100,
        'last_broadcast_percent' => 100,
    ]);

    $response = $this->actingAs($user)->postJson('/api/download-transfers/bulk-delete-completed', [
        'also_from_disk' => true,
    ]);

    $response->assertSuccessful()
        ->assertJson([
            'count' => 1,
        ]);

    expect(DownloadTransfer::query()->whereKey($transfer->id)->exists())->toBeFalse();
    expect(File::query()->whereKey($file->id)->exists())->toBeTrue();

    $file->refresh();
    expect($file->path)->toBeNull();
    expect($file->preview_path)->toBeNull();
    expect($file->downloaded)->toBeFalse();
    expect(Reaction::query()->where('file_id', $file->id)->exists())->toBeTrue();

    Storage::disk('atlas-app')->assertMissing('downloads/completed-video.mp4');
    Storage::disk('atlas-app')->assertMissing('thumbnails/completed-video.jpg');
});

it('removes multiple transfers from disk in one bulk request while keeping Atlas file records by default', function () {
    Storage::fake('atlas-app');

    $user = User::factory()->create();
    $firstFile = File::factory()->create([
        'url' => 'https://example.com/first-video.mp4',
        'filename' => 'first-video.mp4',
        'downloaded' => true,
        'path' => 'downloads/first-video.mp4',
        'preview_path' => 'thumbnails/first-video.jpg',
    ]);
    $secondFile = File::factory()->create([
        'url' => 'https://example.com/second-video.mp4',
        'filename' => 'second-video.mp4',
        'downloaded' => true,
        'path' => 'downloads/second-video.mp4',
        'preview_path' => 'thumbnails/second-video.jpg',
    ]);
    Storage::disk('atlas-app')->put($firstFile->path, 'video');
    Storage::disk('atlas-app')->put($firstFile->preview_path, 'preview');
    Storage::disk('atlas-app')->put($secondFile->path, 'video');
    Storage::disk('atlas-app')->put($secondFile->preview_path, 'preview');

    $firstTransfer = DownloadTransfer::query()->create([
        'file_id' => $firstFile->id,
        'url' => $firstFile->url,
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::COMPLETED,
        'bytes_total' => 100,
        'bytes_downloaded' => 100,
        'last_broadcast_percent' => 100,
    ]);
    $secondTransfer = DownloadTransfer::query()->create([
        'file_id' => $secondFile->id,
        'url' => $secondFile->url,
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::FAILED,
        'bytes_total' => 100,
        'bytes_downloaded' => 10,
        'last_broadcast_percent' => 10,
    ]);

    $response = $this->actingAs($user)->postJson('/api/download-transfers/bulk-delete', [
        'ids' => [$firstTransfer->id, $secondTransfer->id],
        'also_from_disk' => true,
    ]);

    $response->assertSuccessful()
        ->assertJson([
            'count' => 2,
            'queued' => false,
        ]);

    expect(DownloadTransfer::query()->whereKey($firstTransfer->id)->exists())->toBeFalse();
    expect(DownloadTransfer::query()->whereKey($secondTransfer->id)->exists())->toBeFalse();
    expect(File::query()->whereKey($firstFile->id)->exists())->toBeTrue();
    expect(File::query()->whereKey($secondFile->id)->exists())->toBeTrue();

    $firstFile->refresh();
    $secondFile->refresh();
    expect($firstFile->path)->toBeNull();
    expect($secondFile->path)->toBeNull();
    expect($firstFile->downloaded)->toBeFalse();
    expect($secondFile->downloaded)->toBeFalse();

    Storage::disk('atlas-app')->assertMissing('downloads/first-video.mp4');
    Storage::disk('atlas-app')->assertMissing('thumbnails/first-video.jpg');
    Storage::disk('atlas-app')->assertMissing('downloads/second-video.mp4');
    Storage::disk('atlas-app')->assertMissing('thumbnails/second-video.jpg');
});

it('can delete Atlas file records and reactions during bulk delete from disk when requested', function () {
    Storage::fake('atlas-app');

    $user = User::factory()->create();
    $reactionUser = User::factory()->create();
    $file = File::factory()->create([
        'url' => 'https://example.com/bulk-record-delete.mp4',
        'filename' => 'bulk-record-delete.mp4',
        'downloaded' => true,
        'path' => 'downloads/bulk-record-delete.mp4',
        'preview_path' => 'thumbnails/bulk-record-delete.jpg',
    ]);
    Reaction::query()->create([
        'file_id' => $file->id,
        'user_id' => $reactionUser->id,
        'type' => 'funny',
    ]);
    Storage::disk('atlas-app')->put($file->path, 'video');
    Storage::disk('atlas-app')->put($file->preview_path, 'preview');

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
        'bytes_downloaded' => 10,
        'last_broadcast_percent' => 10,
        'failed_at' => now(),
    ]);

    $response = $this->actingAs($user)->postJson('/api/download-transfers/bulk-delete', [
        'ids' => [$firstTransfer->id],
        'also_from_disk' => true,
        'also_delete_record' => true,
    ]);

    $response->assertSuccessful()
        ->assertJson([
            'count' => 2,
            'queued' => false,
        ]);

    expect(File::query()->whereKey($file->id)->exists())->toBeFalse();
    expect(Reaction::query()->where('file_id', $file->id)->exists())->toBeFalse();
    expect(DownloadTransfer::query()->whereKey($firstTransfer->id)->exists())->toBeFalse();
    expect(DownloadTransfer::query()->whereKey($secondTransfer->id)->exists())->toBeFalse();
});

it('queues large bulk transfer removal requests', function () {
    Bus::fake();

    config()->set('downloads.bulk_removal_sync_limit', 1);

    $user = User::factory()->create();
    $file = File::factory()->create([
        'url' => 'https://example.com/queued-removal.bin',
    ]);

    $firstTransfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => 'https://example.com/queued-removal.bin',
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::FAILED,
        'bytes_total' => 100,
        'bytes_downloaded' => 50,
        'last_broadcast_percent' => 50,
    ]);
    $secondTransfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => 'https://example.com/queued-removal.bin',
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::COMPLETED,
        'bytes_total' => 100,
        'bytes_downloaded' => 100,
        'last_broadcast_percent' => 100,
    ]);

    $response = $this->actingAs($user)->postJson('/api/download-transfers/bulk-delete', [
        'ids' => [$firstTransfer->id, $secondTransfer->id],
    ]);

    $response->assertSuccessful()
        ->assertJson([
            'count' => 2,
            'queued' => true,
        ]);

    Bus::assertDispatched(RemoveDownloadTransfers::class, function (RemoveDownloadTransfers $job) use ($firstTransfer, $secondTransfer): bool {
        return $job->ids === [$firstTransfer->id, $secondTransfer->id]
            && $job->alsoFromDisk === false
            && $job->completedOnly === false;
    });

    expect(DownloadTransfer::query()->whereKey($firstTransfer->id)->exists())->toBeTrue();
    expect(DownloadTransfer::query()->whereKey($secondTransfer->id)->exists())->toBeTrue();
});

it('queues large completed transfer removal requests', function () {
    Bus::fake();

    config()->set('downloads.bulk_removal_sync_limit', 1);

    $user = User::factory()->create();
    $file = File::factory()->create([
        'url' => 'https://example.com/completed-removal.bin',
    ]);

    DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => 'https://example.com/completed-removal.bin',
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::COMPLETED,
        'bytes_total' => 100,
        'bytes_downloaded' => 100,
        'last_broadcast_percent' => 100,
    ]);
    DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => 'https://example.com/completed-removal-2.bin',
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::COMPLETED,
        'bytes_total' => 100,
        'bytes_downloaded' => 100,
        'last_broadcast_percent' => 100,
    ]);

    $response = $this->actingAs($user)->postJson('/api/download-transfers/bulk-delete-completed');

    $response->assertSuccessful()
        ->assertJson([
            'count' => 2,
            'queued' => true,
        ]);

    Bus::assertDispatched(RemoveDownloadTransfers::class, fn (RemoveDownloadTransfers $job): bool => $job->completedOnly === true);
});

it('broadcasts removed ids while queued bulk removal jobs run', function () {
    Event::fake([DownloadTransfersRemoved::class]);

    config()->set('downloads.bulk_removal_chunk_size', 1);

    $file = File::factory()->create([
        'url' => 'https://example.com/broadcast-removal.bin',
    ]);

    $firstTransfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => 'https://example.com/broadcast-removal.bin',
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::FAILED,
        'bytes_total' => 100,
        'bytes_downloaded' => 30,
        'last_broadcast_percent' => 30,
    ]);
    $secondTransfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => 'https://example.com/broadcast-removal-2.bin',
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::COMPLETED,
        'bytes_total' => 100,
        'bytes_downloaded' => 100,
        'last_broadcast_percent' => 100,
    ]);

    (new RemoveDownloadTransfers(ids: [$firstTransfer->id, $secondTransfer->id]))
        ->handle(app(DownloadTransferRemovalService::class));

    expect(DownloadTransfer::query()->whereKey($firstTransfer->id)->exists())->toBeFalse();
    expect(DownloadTransfer::query()->whereKey($secondTransfer->id)->exists())->toBeFalse();

    Event::assertDispatched(DownloadTransfersRemoved::class, fn (DownloadTransfersRemoved $event): bool => $event->ids === [$firstTransfer->id]);
    Event::assertDispatched(DownloadTransfersRemoved::class, fn (DownloadTransfersRemoved $event): bool => $event->ids === [$secondTransfer->id]);
});
