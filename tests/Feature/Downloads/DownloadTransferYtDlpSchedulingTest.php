<?php

use App\Enums\DownloadTransferStatus;
use App\Jobs\Downloads\DownloadTransferYtDlp;
use App\Jobs\Downloads\PrepareDownloadTransfer;
use App\Jobs\Downloads\PumpDomainDownloads;
use App\Jobs\Downloads\PumpDomainDownloadsAfterYtDlpRelease;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Services\Downloads\DownloadTransferExecutionLock;
use App\Services\Downloads\DownloadTransferRuntimeStore;
use App\Services\Downloads\DownloadTransferTempDirectory;
use App\Services\Downloads\YtDlpExecutionTeardown;
use App\Services\Downloads\YtDlpUnsupportedUrlFallback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function makeSchedulingYtDlpTransfer(): array
{
    $file = File::factory()->create([
        'source' => 'Extension',
        'url' => 'https://page.example.test/watch/1',
        'preview_url' => 'https://assets.example.test/media.mp4',
        'downloaded' => false,
        'path' => null,
        'listing_metadata' => [
            'download_via' => 'yt-dlp',
            'extension_channel' => 'atlas-extension',
            'tag_name' => 'video',
        ],
    ]);

    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $file->url,
        'domain' => 'page.example.test',
        'status' => DownloadTransferStatus::DOWNLOADING,
        'attempt' => 0,
        'bytes_total' => null,
        'bytes_downloaded' => 0,
        'last_broadcast_percent' => 0,
    ]);

    return [$file, $transfer];
}

it('cleans a stale yt-dlp attempt without pumping while its execution lock is owned', function () {
    Bus::fake([PumpDomainDownloads::class]);
    Storage::fake('atlas');

    [, $transfer] = makeSchedulingYtDlpTransfer();
    $oldDirectory = app(DownloadTransferTempDirectory::class)->ytDlpAttempt($transfer->id, 0);
    $newDirectory = app(DownloadTransferTempDirectory::class)->attempt($transfer->id, 1);
    Storage::disk('atlas')->put($oldDirectory.'/fragment.part', 'partial');
    Storage::disk('atlas')->put($newDirectory.'/replacement.part', 'replacement');

    DownloadTransfer::query()->whereKey($transfer->id)->update([
        'attempt' => 1,
        'status' => DownloadTransferStatus::PENDING,
        'domain' => 'assets.example.test',
    ]);

    $result = app(YtDlpUnsupportedUrlFallback::class)->recover(
        $transfer,
        'ERROR: [generic] Unsupported URL: https://page.example.test/watch/1',
        0,
    );

    expect($result)->not->toBeFalse();
    Storage::disk('atlas')->assertMissing($oldDirectory);
    Storage::disk('atlas')->assertExists($newDirectory.'/replacement.part');
    Bus::assertNotDispatched(PumpDomainDownloads::class);
});

it('returns a queued yt-dlp transfer to pending while the prior process lock remains active', function () {
    Bus::fake([PumpDomainDownloads::class, PumpDomainDownloadsAfterYtDlpRelease::class]);
    [, $transfer] = makeSchedulingYtDlpTransfer();
    $transfer->update([
        'attempt' => 1,
        'status' => DownloadTransferStatus::QUEUED,
    ]);

    $lock = app(DownloadTransferExecutionLock::class)->acquireYtDlp($transfer->id, 30);

    try {
        Http::fake();
        (new PrepareDownloadTransfer($transfer->id, 1))->handle();
    } finally {
        $lock?->release();
    }

    Http::assertNothingSent();
    expect($transfer->fresh()->status)->toBe(DownloadTransferStatus::PENDING);
    Bus::assertDispatched(PumpDomainDownloadsAfterYtDlpRelease::class, function (PumpDomainDownloadsAfterYtDlpRelease $job) use ($transfer): bool {
        return $job->downloadTransferId === $transfer->id
            && $job->releasedDomain === $transfer->domain
            && $job->delay !== null;
    });
    Bus::assertNotDispatched(PumpDomainDownloads::class);
});

it('keeps polling without pumping while the yt-dlp execution lock remains active', function () {
    Bus::fake([PumpDomainDownloads::class, PumpDomainDownloadsAfterYtDlpRelease::class]);
    [, $transfer] = makeSchedulingYtDlpTransfer();
    $lock = app(DownloadTransferExecutionLock::class)->acquireYtDlp($transfer->id, 30);

    try {
        (new PumpDomainDownloadsAfterYtDlpRelease($transfer->id, 'page.example.test'))->handle();
    } finally {
        $lock?->release();
    }

    Bus::assertDispatched(PumpDomainDownloadsAfterYtDlpRelease::class, function (PumpDomainDownloadsAfterYtDlpRelease $job) use ($transfer): bool {
        return $job->downloadTransferId === $transfer->id
            && $job->releasedDomain === 'page.example.test'
            && $job->delay !== null;
    });
    Bus::assertNotDispatched(PumpDomainDownloads::class);
});

it('pumps the released and current domains after the yt-dlp lock clears', function () {
    Bus::fake([PumpDomainDownloads::class, PumpDomainDownloadsAfterYtDlpRelease::class]);
    [, $transfer] = makeSchedulingYtDlpTransfer();
    $transfer->update(['domain' => 'assets.example.test']);

    (new PumpDomainDownloadsAfterYtDlpRelease($transfer->id, 'page.example.test'))->handle();

    Bus::assertDispatched(PumpDomainDownloads::class, fn (PumpDomainDownloads $job): bool => $job->domain === 'page.example.test');
    Bus::assertDispatched(PumpDomainDownloads::class, fn (PumpDomainDownloads $job): bool => $job->domain === 'assets.example.test');
    Bus::assertNotDispatched(PumpDomainDownloadsAfterYtDlpRelease::class);
});

it('does not clear replacement runtime context when a stale yt-dlp failure loses its CAS', function () {
    Bus::fake([PumpDomainDownloads::class]);

    [, $transfer] = makeSchedulingYtDlpTransfer();
    $transfer->update(['attempt' => 1]);
    $runtimeStore = app(DownloadTransferRuntimeStore::class);
    $runtimeStore->putForTransfer($transfer->id, ['user_agent' => 'replacement-agent']);

    $method = new ReflectionMethod(DownloadTransferYtDlp::class, 'failTransfer');
    $method->invoke(
        new DownloadTransferYtDlp($transfer->id, 0),
        $transfer,
        'Old attempt failed.',
        false,
    );

    expect($runtimeStore->getForTransfer($transfer->id)['user_agent'] ?? null)->toBe('replacement-agent')
        ->and($transfer->fresh()->status)->toBe(DownloadTransferStatus::DOWNLOADING);
});

it('cleans same-attempt yt-dlp fragments after a cancel wins the final state race', function () {
    Storage::fake('atlas');
    [, $transfer] = makeSchedulingYtDlpTransfer();
    $directory = app(DownloadTransferTempDirectory::class)->ytDlpAttempt($transfer->id, 0);
    Storage::disk('atlas')->put($directory.'/fragment.part', 'partial');
    $transfer->update(['status' => DownloadTransferStatus::CANCELED]);

    $method = new ReflectionMethod(YtDlpExecutionTeardown::class, 'cleanupAttemptIfNoLongerOwned');
    $method->invoke(app(YtDlpExecutionTeardown::class), $transfer->id, 0);

    Storage::disk('atlas')->assertMissing($directory);
});

it('preserves same-attempt yt-dlp fragments for an explicitly resumable failure', function () {
    Storage::fake('atlas');
    [, $transfer] = makeSchedulingYtDlpTransfer();
    $directory = app(DownloadTransferTempDirectory::class)->ytDlpAttempt($transfer->id, 0);
    Storage::disk('atlas')->put($directory.'/fragment.part', 'partial');
    $transfer->update([
        'status' => DownloadTransferStatus::FAILED,
        'error' => 'HTTP Error 503: Service Unavailable',
    ]);

    $method = new ReflectionMethod(YtDlpExecutionTeardown::class, 'cleanupAttemptIfNoLongerOwned');
    $method->invoke(app(YtDlpExecutionTeardown::class), $transfer->id, 0);

    Storage::disk('atlas')->assertExists($directory.'/fragment.part');
});
