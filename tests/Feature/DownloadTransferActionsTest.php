<?php

use App\Enums\DownloadChunkStatus;
use App\Enums\DownloadTransferStatus;
use App\Events\DownloadTransfersRemoved;
use App\Jobs\Downloads\PumpDomainDownloads;
use App\Jobs\Downloads\RemoveDownloadTransfers;
use App\Models\DownloadChunk;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use App\Services\Downloads\DownloadTransferExecutionLock;
use App\Services\Downloads\DownloadTransferRemovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
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

it('resumes an eligible failed yt-dlp transfer without discarding temp state', function () {
    Bus::fake();
    Storage::fake('atlas-app');

    $user = User::factory()->create();
    $file = File::factory()->create([
        'url' => 'https://example.com/watch?v=resumable',
        'listing_metadata' => ['download_via' => 'yt-dlp', 'tag_name' => 'video'],
    ]);
    File::query()->whereKey($file->id)->update(['download_progress' => 61]);

    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $file->url,
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::FAILED,
        'bytes_total' => null,
        'bytes_downloaded' => 61,
        'last_broadcast_percent' => 61,
        'failed_at' => now(),
        'error' => 'HTTP Error 503: Service Unavailable',
    ]);

    $tmpDir = rtrim((string) config('downloads.tmp_dir'), '/').'/transfer-'.$transfer->id;
    $fragmentPath = $tmpDir.'/download.mp4.part-Frag35.part';
    Storage::disk('atlas-app')->put($fragmentPath, 'partial-fragment');

    $response = $this->actingAs($user)->postJson("/api/download-transfers/{$transfer->id}/resume");

    $response->assertSuccessful();

    $transfer->refresh();
    $file->refresh();

    expect($transfer->status)->toBe(DownloadTransferStatus::PENDING);
    expect($transfer->bytes_downloaded)->toBe(61);
    expect($transfer->last_broadcast_percent)->toBe(61);
    expect($transfer->error)->toBeNull();
    expect($transfer->failed_at)->toBeNull();
    expect($file->download_progress)->toBe(61);

    Storage::disk('atlas-app')->assertExists($fragmentPath);

    Bus::assertDispatched(PumpDomainDownloads::class, fn (PumpDomainDownloads $job) => $job->domain === 'example.com');
});

it('rejects resume for failed transfers that require a scratch restart', function () {
    Bus::fake();

    $user = User::factory()->create();
    $file = File::factory()->create([
        'url' => 'https://example.com/watch?v=restart-only',
        'listing_metadata' => ['download_via' => 'yt-dlp', 'tag_name' => 'video'],
    ]);

    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $file->url,
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::FAILED,
        'bytes_total' => null,
        'bytes_downloaded' => 42,
        'last_broadcast_percent' => 42,
        'failed_at' => now(),
        'error' => 'WARNING: .ytdl file is corrupt. Atlas discarded the temporary yt-dlp fragments for this transfer. Use Restart to fetch the file from scratch.',
    ]);

    $response = $this->actingAs($user)->postJson("/api/download-transfers/{$transfer->id}/resume");

    $response->assertStatus(409)
        ->assertJson([
            'message' => 'Download cannot resume. Use Restart to fetch it from scratch.',
        ]);

    $transfer->refresh();

    expect($transfer->status)->toBe(DownloadTransferStatus::FAILED);
    expect($transfer->error)->toContain('Use Restart to fetch the file from scratch.');

    Bus::assertNotDispatched(PumpDomainDownloads::class);
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

it('keeps active yt-dlp fragments on cancel until the running process exits', function () {
    Storage::fake('atlas-app');

    $user = User::factory()->create();
    $file = File::factory()->create([
        'url' => 'https://example.com/watch?v=active-cancel',
        'listing_metadata' => ['download_via' => 'yt-dlp', 'tag_name' => 'video'],
    ]);
    File::query()->whereKey($file->id)->update(['download_progress' => 35]);
    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $file->url,
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::DOWNLOADING,
        'attempt' => 0,
        'bytes_total' => null,
        'bytes_downloaded' => 35,
        'last_broadcast_percent' => 35,
    ]);

    $tmpDir = rtrim((string) config('downloads.tmp_dir'), '/').'/transfer-'.$transfer->id;
    $fragmentPath = $tmpDir.'/download.mp4.part-Frag33.part';
    Storage::disk('atlas-app')->put($fragmentPath, 'partial-fragment');
    $lock = app(DownloadTransferExecutionLock::class)->acquireYtDlp($transfer->id, 30);

    $response = $this->actingAs($user)->postJson("/api/download-transfers/{$transfer->id}/cancel");

    $response->assertSuccessful();

    $transfer->refresh();
    $file->refresh();

    expect($transfer->status)->toBe(DownloadTransferStatus::CANCELED);
    expect($file->download_progress)->toBe(0);
    Storage::disk('atlas-app')->assertExists($fragmentPath);

    $lock?->release();
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

it('defers restarting yt-dlp until the superseded process releases the transfer lock', function () {
    Bus::fake();
    Storage::fake('atlas-app');

    $user = User::factory()->create();
    $file = File::factory()->create([
        'url' => 'https://example.com/watch?v=restart-race',
        'listing_metadata' => ['download_via' => 'yt-dlp', 'tag_name' => 'video'],
    ]);
    File::query()->whereKey($file->id)->update(['download_progress' => 40]);
    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $file->url,
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::CANCELED,
        'attempt' => 0,
        'bytes_total' => null,
        'bytes_downloaded' => 40,
        'last_broadcast_percent' => 40,
    ]);

    $tmpDir = rtrim((string) config('downloads.tmp_dir'), '/').'/transfer-'.$transfer->id;
    $fragmentPath = $tmpDir.'/download.mp4.part-Frag33.part';
    Storage::disk('atlas-app')->put($fragmentPath, 'partial-fragment');
    $lock = app(DownloadTransferExecutionLock::class)->acquireYtDlp($transfer->id, 30);

    $response = $this->actingAs($user)->postJson("/api/download-transfers/{$transfer->id}/restart");

    $response->assertSuccessful();

    $transfer->refresh();
    $file->refresh();

    expect($transfer->status)->toBe(DownloadTransferStatus::PENDING);
    expect($transfer->attempt)->toBe(1);
    expect($transfer->bytes_downloaded)->toBe(0);
    expect($transfer->last_broadcast_percent)->toBe(0);
    expect($file->download_progress)->toBe(0);

    Storage::disk('atlas-app')->assertExists($fragmentPath);
    Bus::assertNotDispatched(PumpDomainDownloads::class);

    $lock?->release();
});

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

    $user = User::factory()->create();
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

    $response = $this->actingAs($user)->deleteJson("/api/download-transfers/{$transfer->id}/disk");

    $response->assertSuccessful()
        ->assertJson([
            'ids' => [$transfer->id],
            'count' => 1,
            'queued' => false,
        ]);

    expect(DownloadTransfer::query()->whereKey($transfer->id)->exists())->toBeFalse();
    expect(DownloadChunk::query()->where('download_transfer_id', $transfer->id)->count())->toBe(0);

    $file->refresh();
    expect($file->path)->toBeNull();
    expect($file->preview_path)->toBeNull();
    expect($file->downloaded)->toBeFalse();
    expect($file->download_progress)->toBe(0);
});

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
