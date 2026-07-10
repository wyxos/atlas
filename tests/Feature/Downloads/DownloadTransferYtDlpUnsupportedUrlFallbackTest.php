<?php

use App\Enums\DownloadTransferStatus;
use App\Jobs\Downloads\AssembleDownloadTransfer;
use App\Jobs\Downloads\DownloadTransferChunk;
use App\Jobs\Downloads\DownloadTransferSingleStream;
use App\Jobs\Downloads\DownloadTransferYtDlp;
use App\Jobs\Downloads\GenerateTransferPreview;
use App\Jobs\Downloads\PrepareDownloadTransfer;
use App\Jobs\Downloads\PumpDomainDownloads;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Services\Downloads\DownloadTransferProgressBroadcaster;
use App\Services\Downloads\DownloadTransferRequestOptions;
use App\Services\Downloads\DownloadTransferTempDirectory;
use App\Services\Downloads\DownloadUrlResolver;
use App\Services\Downloads\FileDownloadFinalizer;
use App\Services\Downloads\YtDlpCommandBuilder;
use App\Services\Downloads\YtDlpUnsupportedUrlFallback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery\MockInterface;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

/**
 * @return array{0: File, 1: DownloadTransfer, 2: string, 3: string|null}
 */
function makeUnsupportedUrlYtDlpTransfer(?string $assetUrl = 'https://assets.example.test/video.mp4'): array
{
    $pageUrl = 'https://pages.example.test/posts/123';
    $file = File::factory()->create([
        'source' => 'Extension',
        'url' => $pageUrl,
        'referrer_url' => $pageUrl,
        'preview_url' => $assetUrl,
        'downloaded' => false,
        'path' => null,
        'listing_metadata' => [
            'download_via' => 'yt-dlp',
            'extension_channel' => 'stable',
            'page_url' => $pageUrl,
            'tag_name' => 'video',
        ],
    ]);

    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $pageUrl,
        'domain' => 'pages.example.test',
        'status' => DownloadTransferStatus::DOWNLOADING,
        'bytes_total' => null,
        'bytes_downloaded' => 0,
        'last_broadcast_percent' => 0,
    ]);

    return [$file, $transfer, $pageUrl, $assetUrl];
}

function runYtDlpFailure(DownloadTransfer $transfer, string $message): void
{
    $script = <<<'PHP'
fwrite(STDERR, $argv[1]);
exit(1);
PHP;

    mock(YtDlpCommandBuilder::class, function (MockInterface $mock) use ($message, $script): void {
        $mock->shouldReceive('build')
            ->once()
            ->andReturn([PHP_BINARY, '-r', $script, $message]);
    });

    (new DownloadTransferYtDlp($transfer->id))->handle(
        app(FileDownloadFinalizer::class),
        app(YtDlpCommandBuilder::class),
        app(DownloadTransferRequestOptions::class),
    );
}

it('falls back to the native asset when yt-dlp does not support an extension video page', function () {
    Bus::fake([PumpDomainDownloads::class]);
    Storage::fake((string) config('downloads.disk'));

    [$file, $transfer, $pageUrl, $assetUrl] = makeUnsupportedUrlYtDlpTransfer();
    $transfer->update([
        'batch_id' => (string) Str::uuid(),
        'bytes_total' => 1000,
        'bytes_downloaded' => 600,
        'last_broadcast_percent' => 60,
        'queued_at' => now(),
        'started_at' => now(),
        'finished_at' => now(),
        'failed_at' => now(),
        'error' => 'Old failure.',
    ]);
    $file->update(['download_progress' => 60]);
    $tempDirectory = app(DownloadTransferTempDirectory::class)->ytDlpAttempt($transfer->id, 0);
    Storage::disk(config('downloads.disk'))->put($tempDirectory.'/download.mp4.part', 'partial');

    runYtDlpFailure(
        $transfer,
        "WARNING: [generic] Falling back on generic information extractor\nERROR: Unsupported URL: {$pageUrl}\n",
    );

    $transfer->refresh();
    $file->refresh();

    expect($transfer->status)->toBe(DownloadTransferStatus::PENDING)
        ->and($transfer->attempt)->toBe(1)
        ->and($transfer->url)->toBe($assetUrl)
        ->and($transfer->domain)->toBe('assets.example.test')
        ->and($transfer->batch_id)->toBeNull()
        ->and($transfer->bytes_total)->toBeNull()
        ->and($transfer->bytes_downloaded)->toBe(0)
        ->and($transfer->last_broadcast_percent)->toBe(0)
        ->and($transfer->queued_at)->toBeNull()
        ->and($transfer->started_at)->toBeNull()
        ->and($transfer->finished_at)->toBeNull()
        ->and($transfer->failed_at)->toBeNull()
        ->and($transfer->error)->toBeNull()
        ->and($file->download_progress)->toBe(0)
        ->and(data_get($file->listing_metadata, 'download_via'))->toBeNull()
        ->and(data_get($file->listing_metadata, 'download_via_reason'))->toBe('yt-dlp-unsupported-native-fallback')
        ->and(Storage::disk(config('downloads.disk'))->exists($tempDirectory))->toBeFalse();

    Bus::assertDispatched(PumpDomainDownloads::class, fn (PumpDomainDownloads $job): bool => $job->domain === 'assets.example.test');
    Bus::assertDispatched(PumpDomainDownloads::class, fn (PumpDomainDownloads $job): bool => $job->domain === 'pages.example.test');
});

it('redacts an unsupported URL when no native asset candidate is available', function () {
    Bus::fake([PumpDomainDownloads::class]);
    Storage::fake((string) config('downloads.disk'));

    [$file, $transfer, $pageUrl] = makeUnsupportedUrlYtDlpTransfer(null);

    runYtDlpFailure(
        $transfer,
        "WARNING: [generic] Falling back on generic information extractor\nERROR: Unsupported URL: {$pageUrl}\n",
    );

    $transfer->refresh();
    $file->refresh();

    expect($transfer->status)->toBe(DownloadTransferStatus::FAILED)
        ->and($transfer->error)->toContain('Unsupported URL')
        ->and($transfer->error)->toContain('[redacted URL]')
        ->and($transfer->error)->not->toContain($pageUrl)
        ->and(data_get($file->listing_metadata, 'download_via'))->toBe('yt-dlp');
});

it('does not retry the same unsupported URL as a native fallback', function () {
    Bus::fake([PumpDomainDownloads::class]);
    Storage::fake((string) config('downloads.disk'));

    $pageUrl = 'https://pages.example.test/posts/123';
    [$file, $transfer] = makeUnsupportedUrlYtDlpTransfer($pageUrl);

    runYtDlpFailure($transfer, "ERROR: Unsupported URL: {$pageUrl}\n");

    $transfer->refresh();
    $file->refresh();

    expect($transfer->status)->toBe(DownloadTransferStatus::FAILED)
        ->and($transfer->error)->not->toContain($pageUrl)
        ->and(data_get($file->listing_metadata, 'download_via'))->toBe('yt-dlp');
});

it('keeps non-unsupported yt-dlp errors on the yt-dlp failure path', function () {
    Bus::fake([PumpDomainDownloads::class]);
    Storage::fake((string) config('downloads.disk'));

    [$file, $transfer, $pageUrl] = makeUnsupportedUrlYtDlpTransfer();

    runYtDlpFailure($transfer, "ERROR: Authentication is required for {$pageUrl}\n");

    $transfer->refresh();
    $file->refresh();

    expect($transfer->status)->toBe(DownloadTransferStatus::FAILED)
        ->and($transfer->url)->toBe($pageUrl)
        ->and(data_get($file->listing_metadata, 'download_via'))->toBe('yt-dlp');
});

it('falls through to failure when the native candidate changes before the locked transition', function () {
    Bus::fake([PumpDomainDownloads::class]);
    Storage::fake((string) config('downloads.disk'));

    [, $transfer, $pageUrl] = makeUnsupportedUrlYtDlpTransfer();
    $transfer->load('file');
    File::query()->whereKey($transfer->file_id)->update(['preview_url' => null]);

    $handled = app(YtDlpUnsupportedUrlFallback::class)->recover(
        $transfer,
        "ERROR: Unsupported URL: {$pageUrl}\n",
        0,
    );

    expect($handled)->toBeFalse();
    Bus::assertNotDispatched(PumpDomainDownloads::class);
});

it('preserves a stale transfer state for post-lock scheduling', function () {
    Bus::fake([PumpDomainDownloads::class]);
    Storage::fake((string) config('downloads.disk'));

    [, $transfer, $pageUrl] = makeUnsupportedUrlYtDlpTransfer();
    $transfer->load('file');
    DownloadTransfer::query()->whereKey($transfer->id)->update([
        'status' => DownloadTransferStatus::CANCELED,
    ]);

    $handled = app(YtDlpUnsupportedUrlFallback::class)->recover(
        $transfer,
        "ERROR: Unsupported URL: {$pageUrl}\n",
        0,
    );

    $transfer->refresh();

    expect($handled)->not->toBeFalse()
        ->and($transfer->status)->toBe(DownloadTransferStatus::CANCELED);
    Bus::assertNotDispatched(PumpDomainDownloads::class);
});

it('uses the current generation temp directory for a stale yt-dlp marker on a native fallback', function () {
    [, $transfer] = makeUnsupportedUrlYtDlpTransfer();
    $metadata = $transfer->file()->firstOrFail()->listing_metadata;
    $metadata['download_via_reason'] = 'yt-dlp-unsupported-native-fallback';
    $transfer->file()->update(['listing_metadata' => $metadata]);
    $transfer->update([
        'attempt' => 2,
        'url' => 'https://assets.example.test/video.mp4',
    ]);
    $transfer->load('file');

    $directory = app(DownloadTransferTempDirectory::class)->forTransfer($transfer);

    expect($directory)->toBe(app(DownloadTransferTempDirectory::class)->attempt($transfer->id, 2));
});

it('does not route a native unsupported-url fallback back through yt-dlp', function () {
    Bus::fake([
        DownloadTransferYtDlp::class,
        DownloadTransferSingleStream::class,
    ]);

    $assetUrl = 'https://assets.example.test/video.mp4';
    Http::fake(function (Request $request) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 403);
        }

        return Http::response('video', 200, ['Content-Type' => 'video/mp4']);
    });

    $file = File::factory()->create([
        'source' => 'Extension',
        'url' => 'https://pages.example.test/posts/123',
        'preview_url' => $assetUrl,
        'downloaded' => false,
        'path' => null,
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
        'status' => DownloadTransferStatus::QUEUED,
        'bytes_total' => null,
        'bytes_downloaded' => 0,
        'last_broadcast_percent' => 0,
    ]);

    PrepareDownloadTransfer::dispatchSync($transfer->id);

    $transfer->refresh();

    expect($transfer->status)->toBe(DownloadTransferStatus::DOWNLOADING);
    Bus::assertDispatched(DownloadTransferSingleStream::class);
    Bus::assertNotDispatched(DownloadTransferYtDlp::class);
});

it('does not suppress yt-dlp when the transfer is not using the retained native asset', function () {
    Bus::fake([
        DownloadTransferYtDlp::class,
        DownloadTransferSingleStream::class,
    ]);

    Http::fake(fn () => Http::response('', 403));

    $pageUrl = 'https://pages.example.test/posts/123';
    $file = File::factory()->create([
        'source' => 'Extension',
        'url' => $pageUrl,
        'preview_url' => 'https://assets.example.test/video.mp4',
        'listing_metadata' => [
            'download_via_reason' => 'yt-dlp-unsupported-native-fallback',
            'extension_channel' => 'stable',
            'tag_name' => 'video',
        ],
    ]);
    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $pageUrl,
        'domain' => 'pages.example.test',
        'status' => DownloadTransferStatus::QUEUED,
    ]);

    PrepareDownloadTransfer::dispatchSync($transfer->id);

    Bus::assertDispatched(DownloadTransferYtDlp::class);
    Bus::assertNotDispatched(DownloadTransferSingleStream::class);
});

it('rejects an HTML response from a marked native fallback', function () {
    Bus::fake([
        GenerateTransferPreview::class,
        PumpDomainDownloads::class,
    ]);
    Storage::fake((string) config('downloads.disk'));

    $assetUrl = 'https://assets.example.test/video.mp4';
    Http::fake(fn () => Http::response('<html>not media</html>', 200, [
        'Content-Type' => 'text/html; charset=utf-8',
    ]));

    $file = File::factory()->create([
        'source' => 'Extension',
        'url' => 'https://pages.example.test/posts/123',
        'preview_url' => $assetUrl,
        'downloaded' => false,
        'path' => null,
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
        'status' => DownloadTransferStatus::DOWNLOADING,
    ]);

    (new DownloadTransferSingleStream($transfer->id))->handle(
        app(FileDownloadFinalizer::class),
        app(DownloadTransferProgressBroadcaster::class),
        app(DownloadTransferRequestOptions::class),
    );

    $transfer->refresh();
    $file->refresh();

    expect($transfer->status)->toBe(DownloadTransferStatus::FAILED)
        ->and($transfer->error)->toContain('HTML')
        ->and($file->downloaded)->toBeFalse()
        ->and($file->path)->toBeNull();

    Bus::assertNotDispatched(GenerateTransferPreview::class);
});

it('rejects a marked native fallback when its HEAD response is HTML', function () {
    Bus::fake([
        AssembleDownloadTransfer::class,
        DownloadTransferChunk::class,
        DownloadTransferSingleStream::class,
        DownloadTransferYtDlp::class,
    ]);

    $assetUrl = 'https://assets.example.test/video.mp4';
    Http::fake(fn () => Http::response('', 200, [
        'Accept-Ranges' => 'bytes',
        'Content-Length' => '100',
        'Content-Type' => 'text/html; charset=utf-8',
    ]));

    $file = File::factory()->create([
        'source' => 'Extension',
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
        'status' => DownloadTransferStatus::QUEUED,
    ]);

    PrepareDownloadTransfer::dispatchSync($transfer->id);

    $transfer->refresh();

    expect($transfer->status)->toBe(DownloadTransferStatus::FAILED)
        ->and($transfer->error)->toContain('HTML');

    Bus::assertNotDispatched(DownloadTransferYtDlp::class);
    Bus::assertNotDispatched(DownloadTransferSingleStream::class);
});

it('resolves future downloads to the retained native fallback asset', function () {
    [$file, , , $assetUrl] = makeUnsupportedUrlYtDlpTransfer();

    $metadata = $file->listing_metadata;
    unset($metadata['download_via']);
    $metadata['download_via_reason'] = 'yt-dlp-unsupported-native-fallback';
    $file->update(['listing_metadata' => $metadata]);

    $resolved = app(DownloadUrlResolver::class)->resolve($file->fresh());

    expect($resolved->url)->toBe($assetUrl);
});

it('accepts an extension iframe asset as a native candidate', function () {
    $file = File::factory()->create([
        'preview_url' => 'https://assets.example.test/embed.mp4',
        'listing_metadata' => [
            'extension_channel' => 'stable',
            'tag_name' => 'iframe',
        ],
    ]);

    expect(YtDlpUnsupportedUrlFallback::nativeUrl($file))->toBe($file->preview_url);
});

it('rejects an ineligible native fallback candidate', function (?string $previewUrl, array $metadata) {
    $file = File::factory()->create([
        'preview_url' => $previewUrl,
        'listing_metadata' => $metadata,
    ]);

    expect(YtDlpUnsupportedUrlFallback::nativeUrl($file))->toBeNull();
})->with([
    'missing extension provenance' => [
        'https://assets.example.test/media.mp4',
        ['tag_name' => 'video'],
    ],
    'non-media tag' => [
        'https://assets.example.test/media.mp4',
        ['extension_channel' => 'stable', 'tag_name' => 'img'],
    ],
    'non-http scheme' => [
        'javascript:private-value',
        ['extension_channel' => 'stable', 'tag_name' => 'video'],
    ],
    'relative candidate' => [
        '/media.mp4',
        ['extension_channel' => 'stable', 'tag_name' => 'video'],
    ],
]);
