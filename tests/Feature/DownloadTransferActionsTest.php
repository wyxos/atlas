<?php

use App\Enums\DownloadChunkStatus;
use App\Enums\DownloadTransferStatus;
use App\Jobs\Downloads\PumpDomainDownloads;
use App\Jobs\Downloads\PumpDomainDownloadsAfterYtDlpRelease;
use App\Models\DownloadChunk;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Models\User;
use App\Services\Downloads\DownloadTransferActionTransition;
use App\Services\Downloads\DownloadTransferExecutionLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('does not let a stale paused resume overwrite a concurrent cancel', function () {
    Storage::fake('atlas');
    $file = File::factory()->create(['url' => 'https://example.test/file.bin']);
    $paused = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $file->url,
        'domain' => 'example.test',
        'status' => DownloadTransferStatus::PAUSED,
        'attempt' => 1,
    ]);
    $stalePaused = $paused->replicate()->setAttribute('id', $paused->id);

    expect(app(DownloadTransferActionTransition::class)->cancel($paused))->toBeTrue()
        ->and(app(DownloadTransferActionTransition::class)->resumeFromScratch($stalePaused))->toBeFalse()
        ->and($paused->fresh()->status)->toBe(DownloadTransferStatus::CANCELED)
        ->and($paused->fresh()->attempt)->toBe(1);
});

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
    Storage::fake('atlas');

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
    Storage::disk('atlas')->put($fragmentPath, 'partial-fragment');

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

    Storage::disk('atlas')->assertExists($fragmentPath);

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
        'url' => 'https://old.example.test/watch/restart-race',
        'domain' => 'old.example.test',
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
    Bus::fake();
    Storage::fake('atlas');

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
    Storage::disk('atlas')->put($fragmentPath, 'partial-fragment');
    $lock = app(DownloadTransferExecutionLock::class)->acquireYtDlp($transfer->id, 30);

    $response = $this->actingAs($user)->postJson("/api/download-transfers/{$transfer->id}/cancel");

    $response->assertSuccessful();

    $transfer->refresh();
    $file->refresh();

    expect($transfer->status)->toBe(DownloadTransferStatus::CANCELED);
    expect($file->download_progress)->toBe(0);
    Storage::disk('atlas')->assertExists($fragmentPath);
    Bus::assertDispatched(PumpDomainDownloadsAfterYtDlpRelease::class, function (PumpDomainDownloadsAfterYtDlpRelease $job) use ($transfer): bool {
        return $job->downloadTransferId === $transfer->id
            && $job->releasedDomain === 'example.com'
            && $job->delay !== null;
    });
    Bus::assertNotDispatched(PumpDomainDownloads::class);

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

it('restarts a native yt-dlp fallback with its retained direct asset', function () {
    Bus::fake();

    $user = User::factory()->create();
    $assetUrl = 'https://assets.example.test/video.mp4';
    $file = File::factory()->create([
        'url' => 'https://pages.example.test/posts/123',
        'preview_url' => $assetUrl,
        'listing_metadata' => [
            'download_via_reason' => 'yt-dlp-unsupported-native-fallback',
            'extension_channel' => 'stable',
            'tag_name' => 'video',
        ],
    ]);
    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $assetUrl,
        'domain' => 'assets.example.test',
        'status' => DownloadTransferStatus::FAILED,
        'failed_at' => now(),
        'error' => 'Native fallback failed.',
    ]);

    $response = $this->actingAs($user)->postJson("/api/download-transfers/{$transfer->id}/restart");

    $response->assertSuccessful();

    $transfer->refresh();

    expect($transfer->status)->toBe(DownloadTransferStatus::PENDING)
        ->and($transfer->url)->toBe($assetUrl)
        ->and($transfer->domain)->toBe('assets.example.test')
        ->and($transfer->attempt)->toBe(1);

    Bus::assertDispatched(PumpDomainDownloads::class, fn (PumpDomainDownloads $job) => $job->domain === 'assets.example.test');
});

it('treats a marked native fallback as native even if a stale yt-dlp marker remains', function () {
    Bus::fake();

    $user = User::factory()->create();
    $assetUrl = 'https://assets.example.test/media.mp4';
    $file = File::factory()->create([
        'url' => 'https://page.example.test/watch/1',
        'preview_url' => $assetUrl,
        'listing_metadata' => [
            'download_via' => 'yt-dlp',
            'download_via_reason' => 'yt-dlp-unsupported-native-fallback',
            'extension_channel' => 'stable',
            'tag_name' => 'video',
        ],
    ]);
    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $assetUrl,
        'domain' => 'assets.example.test',
        'status' => DownloadTransferStatus::FAILED,
        'attempt' => 3,
        'failed_at' => now(),
        'error' => 'Native fallback failed.',
    ]);
    $lock = app(DownloadTransferExecutionLock::class)->acquireYtDlp($transfer->id, 30);

    try {
        $response = $this->actingAs($user)->postJson("/api/download-transfers/{$transfer->id}/restart");
    } finally {
        $lock?->release();
    }

    $response->assertSuccessful();
    expect($transfer->fresh()->status)->toBe(DownloadTransferStatus::PENDING)
        ->and($transfer->fresh()->attempt)->toBe(4);
    Bus::assertDispatched(PumpDomainDownloads::class, fn (PumpDomainDownloads $job) => $job->domain === 'assets.example.test');
});

it('defers restarting yt-dlp until the superseded process releases the transfer lock', function () {
    Bus::fake();
    Storage::fake('atlas');

    $user = User::factory()->create();
    $file = File::factory()->create([
        'url' => 'https://example.com/watch?v=restart-race',
        'listing_metadata' => ['download_via' => 'yt-dlp', 'tag_name' => 'video'],
    ]);
    File::query()->whereKey($file->id)->update(['download_progress' => 40]);
    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => 'https://old.example.test/watch/restart-race',
        'domain' => 'old.example.test',
        'status' => DownloadTransferStatus::CANCELED,
        'attempt' => 0,
        'bytes_total' => null,
        'bytes_downloaded' => 40,
        'last_broadcast_percent' => 40,
    ]);

    $tmpDir = rtrim((string) config('downloads.tmp_dir'), '/').'/transfer-'.$transfer->id;
    $fragmentPath = $tmpDir.'/download.mp4.part-Frag33.part';
    Storage::disk('atlas')->put($fragmentPath, 'partial-fragment');
    $lock = app(DownloadTransferExecutionLock::class)->acquireYtDlp($transfer->id, 30);

    $response = $this->actingAs($user)->postJson("/api/download-transfers/{$transfer->id}/restart");

    $response->assertSuccessful();

    $transfer->refresh();
    $file->refresh();

    expect($transfer->status)->toBe(DownloadTransferStatus::PENDING);
    expect($transfer->attempt)->toBe(1);
    expect($transfer->domain)->toBe('example.com');
    expect($transfer->bytes_downloaded)->toBe(0);
    expect($transfer->last_broadcast_percent)->toBe(0);
    expect($file->download_progress)->toBe(0);

    Storage::disk('atlas')->assertExists($fragmentPath);
    Bus::assertDispatched(PumpDomainDownloadsAfterYtDlpRelease::class, function (PumpDomainDownloadsAfterYtDlpRelease $job) use ($transfer): bool {
        return $job->downloadTransferId === $transfer->id
            && $job->releasedDomain === 'old.example.test'
            && $job->delay !== null;
    });
    Bus::assertNotDispatched(PumpDomainDownloads::class);

    $lock?->release();
});
