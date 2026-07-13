<?php

use App\Enums\DownloadChunkStatus;
use App\Enums\DownloadTransferStatus;
use App\Jobs\Downloads\DownloadTransferChunk;
use App\Jobs\Downloads\DownloadTransferSingleStream;
use App\Jobs\Downloads\PrepareDownloadTransfer;
use App\Jobs\Downloads\PumpDomainDownloads;
use App\Models\DeviantArtToken;
use App\Models\DownloadChunk;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Models\User;
use App\Services\Downloads\DownloadTransferProgressBroadcaster;
use App\Services\Downloads\DownloadTransferRequestOptions;
use App\Services\Downloads\DownloadTransferRuntimeStore;
use App\Services\Downloads\FileDownloadFinalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('atlas');
    Cache::flush();
    Bus::fake();

    $this->user = User::factory()->create();
    DeviantArtToken::query()->create([
        'user_id' => $this->user->id,
        'access_token' => 'connected-access-token',
        'refresh_token' => 'connected-refresh-token',
        'scope' => 'browse user',
        'expires_at' => now()->addHour(),
    ]);

    $this->deviationId = '52BAFA97-9DB9-0E5F-FF2D-C39083F89817';
    $this->downloadApiUrl = "https://www.deviantart.com/api/v1/oauth2/deviation/download/{$this->deviationId}";
    $this->signedUrl = function (string $host, int $expiresAt): string {
        $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'none'], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode(['exp' => $expiresAt], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return "https://{$host}/original.png?token={$header}.{$payload}.signature";
    };
});

it('resolves a fresh provider URL when restarting a failed DeviantArt transfer', function () {
    $expiresAt = now()->addMinutes(10)->timestamp;
    $freshUrl = ($this->signedUrl)('fresh-download.example.test', $expiresAt);
    Http::fake([
        $this->downloadApiUrl => Http::response([
            'src' => $freshUrl,
            'filename' => 'original.png',
            'filesize' => 8192,
        ]),
    ]);

    $file = File::factory()->create([
        'source' => 'deviantart.com',
        'source_id' => $this->deviationId,
        'url' => 'https://listing.example.test/content.jpg',
        'listing_metadata' => [
            'deviationid' => $this->deviationId,
            'is_downloadable' => true,
        ],
        'download_progress' => 40,
    ]);
    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => 'https://expired-download.example.test/original.png',
        'domain' => 'expired-download.example.test',
        'status' => DownloadTransferStatus::FAILED,
        'attempt' => 2,
        'bytes_total' => 100,
        'bytes_downloaded' => 40,
        'failed_at' => now(),
        'error' => 'Invalid download response (status 401).',
    ]);

    $response = $this->actingAs($this->user)
        ->postJson("/api/download-transfers/{$transfer->id}/restart");

    $response->assertSuccessful();
    $transfer->refresh();
    $runtimeContext = app(DownloadTransferRuntimeStore::class)->getForTransfer($transfer->id);

    expect($transfer->status)->toBe(DownloadTransferStatus::PENDING)
        ->and($transfer->attempt)->toBe(3)
        ->and($transfer->url)->toBe($freshUrl)
        ->and($transfer->domain)->toBe('fresh-download.example.test')
        ->and($transfer->bytes_downloaded)->toBe(0)
        ->and($runtimeContext['user_id'] ?? null)->toBe($this->user->id)
        ->and($runtimeContext['provider_url_expires_at'] ?? null)->toBe($expiresAt)
        ->and($runtimeContext['provider_url_refresh_attempted'] ?? null)->toBeFalse();

    Bus::assertDispatched(PumpDomainDownloads::class, fn (PumpDomainDownloads $job): bool => $job->domain === 'fresh-download.example.test');
});

it('does not restart a dynamic provider transfer with its stale URL when resolution fails', function () {
    Http::fake();
    $disconnectedUser = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'deviantart.com',
        'source_id' => $this->deviationId,
        'url' => 'https://listing.example.test/content.jpg',
        'listing_metadata' => [
            'deviationid' => $this->deviationId,
            'is_downloadable' => true,
        ],
    ]);
    $expiredUrl = 'https://expired-download.example.test/original.png';
    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $expiredUrl,
        'domain' => 'expired-download.example.test',
        'status' => DownloadTransferStatus::FAILED,
        'attempt' => 2,
        'failed_at' => now(),
        'error' => 'Invalid download response (status 401).',
    ]);

    $response = $this->actingAs($disconnectedUser)
        ->postJson("/api/download-transfers/{$transfer->id}/restart");

    $response->assertStatus(409);
    $transfer->refresh();

    expect($transfer->status)->toBe(DownloadTransferStatus::FAILED)
        ->and($transfer->attempt)->toBe(2)
        ->and($transfer->url)->toBe($expiredUrl);

    Http::assertNothingSent();
    Bus::assertNotDispatched(PumpDomainDownloads::class);
});

it('restarts a known non-downloadable DeviantArt transfer with its stable listing URL', function () {
    Http::fake();
    $listingUrl = 'https://listing.example.test/content.jpg';
    $file = File::factory()->create([
        'source' => 'deviantart.com',
        'source_id' => $this->deviationId,
        'url' => $listingUrl,
        'listing_metadata' => [
            'deviationid' => $this->deviationId,
            'is_downloadable' => false,
        ],
    ]);
    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $listingUrl,
        'domain' => 'listing.example.test',
        'status' => DownloadTransferStatus::FAILED,
        'attempt' => 1,
        'failed_at' => now(),
    ]);

    $response = $this->actingAs($this->user)
        ->postJson("/api/download-transfers/{$transfer->id}/restart");

    $response->assertSuccessful();
    $transfer->refresh();

    expect($transfer->status)->toBe(DownloadTransferStatus::PENDING)
        ->and($transfer->attempt)->toBe(2)
        ->and($transfer->url)->toBe($listingUrl);

    Http::assertNothingSent();
});

it('refreshes once after an unauthorized single stream response', function () {
    $expiresAt = now()->addMinutes(10)->timestamp;
    $expiredUrl = 'https://expired-download.example.test/original.png';
    $freshUrl = ($this->signedUrl)('fresh-download.example.test', $expiresAt);
    Http::fake([
        $expiredUrl => Http::response('', 401),
        $this->downloadApiUrl => Http::response([
            'src' => $freshUrl,
            'filename' => 'original.png',
            'filesize' => 8192,
        ]),
    ]);

    $file = File::factory()->create([
        'source' => 'deviantart.com',
        'source_id' => $this->deviationId,
        'url' => 'https://listing.example.test/content.jpg',
        'listing_metadata' => [
            'deviationid' => $this->deviationId,
            'is_downloadable' => true,
        ],
        'download_progress' => 20,
    ]);
    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $expiredUrl,
        'domain' => 'expired-download.example.test',
        'status' => DownloadTransferStatus::DOWNLOADING,
        'attempt' => 0,
        'bytes_total' => 100,
        'bytes_downloaded' => 20,
    ]);
    app(DownloadTransferRuntimeStore::class)->putForTransfer($transfer->id, [
        'user_id' => $this->user->id,
        'provider_url_refresh_attempted' => false,
    ]);
    $this->mock(FileDownloadFinalizer::class, function (MockInterface $mock): void {
        $mock->shouldReceive('finalize')->never();
    });

    (new DownloadTransferSingleStream($transfer->id, 'image/png', 0))->handle(
        app(FileDownloadFinalizer::class),
        app(DownloadTransferProgressBroadcaster::class),
        app(DownloadTransferRequestOptions::class),
    );

    $transfer->refresh();
    $runtimeContext = app(DownloadTransferRuntimeStore::class)->getForTransfer($transfer->id);

    expect($transfer->status)->toBe(DownloadTransferStatus::PENDING)
        ->and($transfer->attempt)->toBe(1)
        ->and($transfer->url)->toBe($freshUrl)
        ->and($transfer->bytes_downloaded)->toBe(0)
        ->and($runtimeContext['provider_url_refresh_attempted'] ?? null)->toBeTrue()
        ->and($runtimeContext['provider_url_expires_at'] ?? null)->toBe($expiresAt);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === $expiredUrl);
    Http::assertSent(fn (Request $request): bool => $request->url() === $this->downloadApiUrl);
});

it('refreshes once after an unauthorized preparation probe', function () {
    $expiresAt = now()->addMinutes(10)->timestamp;
    $expiredUrl = 'https://expired-download.example.test/original.png';
    $freshUrl = ($this->signedUrl)('fresh-download.example.test', $expiresAt);
    Http::fake([
        $expiredUrl => Http::response('', 403),
        $this->downloadApiUrl => Http::response([
            'src' => $freshUrl,
            'filename' => 'original.png',
            'filesize' => 8192,
        ]),
    ]);

    $file = File::factory()->create([
        'source' => 'deviantart.com',
        'source_id' => $this->deviationId,
        'url' => 'https://listing.example.test/content.jpg',
        'listing_metadata' => [
            'deviationid' => $this->deviationId,
            'is_downloadable' => true,
        ],
    ]);
    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $expiredUrl,
        'domain' => 'expired-download.example.test',
        'status' => DownloadTransferStatus::QUEUED,
        'attempt' => 0,
    ]);
    app(DownloadTransferRuntimeStore::class)->putForTransfer($transfer->id, [
        'user_id' => $this->user->id,
        'provider_url_refresh_attempted' => false,
    ]);

    (new PrepareDownloadTransfer($transfer->id, 0))->handle();

    $transfer->refresh();

    expect($transfer->status)->toBe(DownloadTransferStatus::PENDING)
        ->and($transfer->attempt)->toBe(1)
        ->and($transfer->url)->toBe($freshUrl)
        ->and($transfer->domain)->toBe('fresh-download.example.test');

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'HEAD' && $request->url() === $expiredUrl);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->url() === $this->downloadApiUrl);
});

it('refreshes an expired provider URL before a chunk request and discards partial chunks', function () {
    $expiresAt = now()->addMinutes(10)->timestamp;
    $expiredUrl = 'https://expired-download.example.test/original.png';
    $freshUrl = ($this->signedUrl)('fresh-download.example.test', $expiresAt);
    Http::fake([
        $this->downloadApiUrl => Http::response([
            'src' => $freshUrl,
            'filename' => 'original.png',
            'filesize' => 8192,
        ]),
    ]);

    $file = File::factory()->create([
        'source' => 'deviantart.com',
        'source_id' => $this->deviationId,
        'url' => 'https://listing.example.test/content.jpg',
        'listing_metadata' => [
            'deviationid' => $this->deviationId,
            'is_downloadable' => true,
        ],
        'download_progress' => 50,
    ]);
    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $expiredUrl,
        'domain' => 'expired-download.example.test',
        'status' => DownloadTransferStatus::DOWNLOADING,
        'attempt' => 4,
        'bytes_total' => 20,
        'bytes_downloaded' => 10,
    ]);
    $chunk = DownloadChunk::query()->create([
        'download_transfer_id' => $transfer->id,
        'index' => 0,
        'range_start' => 0,
        'range_end' => 19,
        'bytes_downloaded' => 10,
        'status' => DownloadChunkStatus::DOWNLOADING,
        'part_path' => "downloads/.tmp/transfer-{$transfer->id}/part-0.part",
    ]);
    app(DownloadTransferRuntimeStore::class)->putForTransfer($transfer->id, [
        'user_id' => $this->user->id,
        'provider_url_expires_at' => now()->subSecond()->timestamp,
        'provider_url_refresh_attempted' => false,
    ]);

    (new DownloadTransferChunk($transfer->id, $chunk->id, 'image/png', 4))->handle(
        app(DownloadTransferProgressBroadcaster::class),
        app(DownloadTransferRequestOptions::class),
    );

    $transfer->refresh();

    expect($transfer->status)->toBe(DownloadTransferStatus::PENDING)
        ->and($transfer->attempt)->toBe(5)
        ->and($transfer->url)->toBe($freshUrl)
        ->and(DownloadChunk::query()->where('download_transfer_id', $transfer->id)->exists())->toBeFalse();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === $this->downloadApiUrl);
    Http::assertNotSent(fn (Request $request): bool => $request->url() === $expiredUrl);
});
