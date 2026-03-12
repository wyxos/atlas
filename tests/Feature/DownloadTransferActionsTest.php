<?php

use App\Enums\DownloadChunkStatus;
use App\Enums\DownloadTransferStatus;
use App\Jobs\Downloads\PumpDomainDownloads;
use App\Models\DownloadChunk;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Models\User;
use App\Services\Downloads\DownloadTransferExecutionLock;
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

it('removes completed transfers and deletes their files from disk when requested', function () {
    Storage::fake('atlas-app');

    $user = User::factory()->create();
    $file = File::factory()->create([
        'url' => 'https://example.com/completed-video.mp4',
        'filename' => 'completed-video.mp4',
        'downloaded' => true,
        'path' => 'downloads/completed-video.mp4',
        'preview_path' => 'thumbnails/completed-video.jpg',
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

    $file->refresh();
    expect($file->path)->toBeNull();
    expect($file->preview_path)->toBeNull();
    expect($file->downloaded)->toBeFalse();
    expect($file->download_progress)->toBe(0);

    Storage::disk('atlas-app')->assertMissing('downloads/completed-video.mp4');
    Storage::disk('atlas-app')->assertMissing('thumbnails/completed-video.jpg');
});
