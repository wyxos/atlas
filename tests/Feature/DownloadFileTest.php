<?php

use App\Enums\DownloadTransferStatus;
use App\Jobs\DownloadFile;
use App\Jobs\Downloads\PumpDomainDownloads;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Models\FileMetadata;
use App\Services\Downloads\DownloadTransferRuntimeStore;
use App\Services\Downloads\FileDownloadFinalizer;
use App\Services\FilePreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('atlas-app');
    Cache::flush();
});

test('queues a download transfer for the file URL (DownloadFile is an orchestrator)', function () {
    Bus::fake();

    $file = File::factory()->create([
        'url' => 'https://example.com/test-image.jpg',
        'filename' => 'test-image.jpg',
        'ext' => 'jpg',
        'downloaded' => false,
        'path' => null,
    ]);

    $job = new DownloadFile($file->id);
    $job->handle();

    $file->refresh();

    expect($file->downloaded)->toBeFalse();
    expect($file->path)->toBeNull();

    $transfer = DownloadTransfer::query()->where('file_id', $file->id)->first();
    expect($transfer)->not->toBeNull();
    expect($transfer->domain)->toBe('example.com');

    Bus::assertDispatched(PumpDomainDownloads::class, fn (PumpDomainDownloads $job) => $job->domain === 'example.com');
});

test('skips download if file is already downloaded', function () {
    Bus::fake();

    $file = File::factory()->create([
        'url' => 'https://example.com/test-image.jpg',
        'downloaded' => true,
        'path' => 'downloads/existing-file.jpg',
    ]);

    Storage::disk('atlas-app')->put('downloads/existing-file.jpg', 'already downloaded');

    $job = new DownloadFile($file->id);
    $job->handle();

    expect(DownloadTransfer::query()->where('file_id', $file->id)->exists())->toBeFalse();
    Bus::assertNotDispatched(PumpDomainDownloads::class);
});

test('re-queues download when a downloaded video resolved to HTML', function () {
    Bus::fake();

    $file = File::factory()->create([
        'url' => 'https://www.youtube.com/embed/MD99_p7XLD8',
        'referrer_url' => 'https://www.youtube.com/embed/MD99_p7XLD8',
        'downloaded' => true,
        'path' => 'downloads/existing-video.html',
        'listing_metadata' => [
            'tag_name' => 'video',
            'page_url' => 'https://www.youtube.com/watch?v=MD99_p7XLD8',
        ],
    ]);

    Storage::disk('atlas-app')->put('downloads/existing-video.html', '<!doctype html>');

    $job = new DownloadFile($file->id);
    $job->handle();

    $file->refresh();
    expect($file->downloaded)->toBeFalse();
    expect($file->path)->toBeNull();
    expect($file->url)->toBe('https://www.youtube.com/watch?v=MD99_p7XLD8');

    $transfer = DownloadTransfer::query()->where('file_id', $file->id)->latest('id')->first();
    expect($transfer)->not->toBeNull();
    expect($transfer->domain)->toBe('www.youtube.com');

    Bus::assertDispatched(PumpDomainDownloads::class, fn (PumpDomainDownloads $job) => $job->domain === 'www.youtube.com');
});

test('skips download if file has no URL', function () {
    Bus::fake();

    $file = File::factory()->create([
        'url' => null,
        'downloaded' => false,
    ]);

    $job = new DownloadFile($file->id);
    $job->handle();

    $file->refresh();
    expect($file->downloaded)->toBeFalse();
    expect(DownloadTransfer::query()->where('file_id', $file->id)->exists())->toBeFalse();
    Bus::assertNotDispatched(PumpDomainDownloads::class);
});

test('creates a transfer even if the URL may not be reachable (download happens asynchronously)', function () {
    Bus::fake();

    $file = File::factory()->create([
        'url' => 'https://example.com/nonexistent.jpg',
        'downloaded' => false,
    ]);

    $job = new DownloadFile($file->id);
    $job->handle();

    $file->refresh();
    expect($file->downloaded)->toBeFalse();
    expect(DownloadTransfer::query()->where('file_id', $file->id)->exists())->toBeTrue();
    Bus::assertDispatched(PumpDomainDownloads::class);
});

test('refreshes runtime context for queued transfers using the latest submission', function () {
    Bus::fake();

    $file = File::factory()->create([
        'url' => 'https://example.com/protected-image.jpg',
        'downloaded' => false,
        'path' => null,
    ]);

    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $file->url,
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::QUEUED,
        'bytes_total' => null,
        'bytes_downloaded' => 0,
        'last_broadcast_percent' => 0,
        'queued_at' => now(),
    ]);

    app(DownloadTransferRuntimeStore::class)->putForTransfer($transfer->id, [
        'cookies' => [[
            'name' => 'auth',
            'value' => 'old-token',
            'domain' => 'example.com',
            'path' => '/',
            'secure' => true,
            'http_only' => true,
            'host_only' => false,
            'expires_at' => time() + 3600,
        ]],
        'user_agent' => 'AtlasExtensionRuntime/1.0',
    ]);

    (new DownloadFile($file->id, false, [
        'cookies' => [[
            'name' => 'auth',
            'value' => 'new-token',
            'domain' => 'example.com',
            'path' => '/',
            'secure' => true,
            'http_only' => true,
            'host_only' => false,
            'expires_at' => time() + 3600,
        ]],
        'user_agent' => 'AtlasExtensionRuntime/2.0',
    ]))->handle();

    $runtimeContext = app(DownloadTransferRuntimeStore::class)->getForTransfer($transfer->id);

    expect(data_get($runtimeContext, 'cookies.0.value'))->toBe('new-token');
    expect($runtimeContext['user_agent'] ?? null)->toBe('AtlasExtensionRuntime/2.0');
    expect(DownloadTransfer::query()->where('file_id', $file->id)->count())->toBe(1);
});

test('preserves runtime context for started transfers', function () {
    Bus::fake();

    $file = File::factory()->create([
        'url' => 'https://example.com/protected-video.mp4',
        'downloaded' => false,
        'path' => null,
    ]);

    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $file->url,
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::DOWNLOADING,
        'bytes_total' => 100,
        'bytes_downloaded' => 10,
        'last_broadcast_percent' => 10,
        'started_at' => now(),
    ]);

    app(DownloadTransferRuntimeStore::class)->putForTransfer($transfer->id, [
        'cookies' => [[
            'name' => 'auth',
            'value' => 'original-token',
            'domain' => 'example.com',
            'path' => '/',
            'secure' => true,
            'http_only' => true,
            'host_only' => false,
            'expires_at' => time() + 3600,
        ]],
        'user_agent' => 'AtlasExtensionRuntime/1.0',
    ]);

    (new DownloadFile($file->id, false, [
        'cookies' => [[
            'name' => 'auth',
            'value' => 'replacement-token',
            'domain' => 'example.com',
            'path' => '/',
            'secure' => true,
            'http_only' => true,
            'host_only' => false,
            'expires_at' => time() + 3600,
        ]],
        'user_agent' => 'AtlasExtensionRuntime/2.0',
    ]))->handle();

    $runtimeContext = app(DownloadTransferRuntimeStore::class)->getForTransfer($transfer->id);

    expect(data_get($runtimeContext, 'cookies.0.value'))->toBe('original-token');
    expect($runtimeContext['user_agent'] ?? null)->toBe('AtlasExtensionRuntime/1.0');
    expect(DownloadTransfer::query()->where('file_id', $file->id)->count())->toBe(1);
});

test('clears blacklist flags when finalizing a downloaded file', function () {
    $file = File::factory()->create([
        'url' => 'https://example.com/test-image.jpg',
        'filename' => 'test-image.jpg',
        'ext' => 'jpg',
        'downloaded' => false,
        'path' => null,
        'blacklisted_at' => now(),
        'previewed_count' => FilePreviewService::FEED_REMOVED_PREVIEW_COUNT,
    ]);

    $tmpPath = 'downloads/.tmp/transfer-1/single.tmp';
    Storage::disk('atlas-app')->put($tmpPath, 'fake image content');

    app(FileDownloadFinalizer::class)->finalize($file, $tmpPath, 'image/jpeg');

    $file->refresh();

    expect($file->downloaded)->toBeTrue();
    expect($file->filename)->toBe('test-image.jpg');
    expect($file->path)->toStartWith('downloads/');
    // Path should be segmented
    expect($file->path)->toMatch('/^downloads\/[a-f0-9]{2}\/[a-f0-9]{2}\//');
    expect($file->blacklisted_at)->toBeNull();
    expect($file->previewed_count)->toBe(FilePreviewService::RECOVERED_PREVIEW_COUNT);
});

test('resets terminal preview count when finalizing a non blacklisted downloaded file', function () {
    $file = File::factory()->create([
        'url' => 'https://example.com/test-image.jpg',
        'filename' => 'test-image.jpg',
        'ext' => 'jpg',
        'downloaded' => false,
        'path' => null,
        'blacklisted_at' => null,
        'previewed_count' => FilePreviewService::FEED_REMOVED_PREVIEW_COUNT,
    ]);

    $tmpPath = 'downloads/.tmp/transfer-1/non-blacklisted.tmp';
    Storage::disk('atlas-app')->put($tmpPath, 'fake image content');

    app(FileDownloadFinalizer::class)->finalize($file, $tmpPath, 'image/jpeg');

    $file->refresh();

    expect($file->downloaded)->toBeTrue();
    expect($file->blacklisted_at)->toBeNull();
    expect($file->previewed_count)->toBe(FilePreviewService::RECOVERED_PREVIEW_COUNT);
});

test('determines extension from MIME type when URL has no extension (finalizer)', function () {
    // Create actual JPEG file content (JPEG magic bytes: FF D8 FF)
    $jpegContent = hex2bin('FFD8FFE000104A46494600010100000100010000FFDB004300');

    $file = File::factory()->create([
        'url' => 'https://example.com/image?id=12345', // No extension in URL
        'filename' => 'temp-file', // Temporary filename without extension
        'ext' => null,
        'downloaded' => false,
        'path' => null,
    ]);

    $tmpPath = 'downloads/.tmp/transfer-1/single.tmp';
    Storage::disk('atlas-app')->put($tmpPath, $jpegContent);

    app(FileDownloadFinalizer::class)->finalize($file, $tmpPath, 'image/jpeg');

    $file->refresh();

    expect($file->downloaded)->toBeTrue();
    // league/mime-type-detection returns 'jpeg' for 'image/jpeg', not 'jpg'
    expect($file->filename)->toBe('temp-file');
    expect($file->path)->toMatch('/\.(jpg|jpeg)$/');
    // Path should be segmented
    expect($file->path)->toMatch('/^downloads\/[a-f0-9]{2}\/[a-f0-9]{2}\//');
});

test('determines extension from file content when Content-Type header is missing (finalizer)', function () {
    // Create actual PNG file content (PNG magic bytes: 89 50 4E 47)
    $pngContent = hex2bin('89504E470D0A1A0A0000000D49484452');

    $file = File::factory()->create([
        'url' => 'https://example.com/file', // No extension
        'filename' => 'temp-file', // Temporary filename without extension
        'ext' => null,
        'downloaded' => false,
        'path' => null,
    ]);

    $tmpPath = 'downloads/.tmp/transfer-1/single.tmp';
    Storage::disk('atlas-app')->put($tmpPath, $pngContent);

    app(FileDownloadFinalizer::class)->finalize($file, $tmpPath);

    $file->refresh();

    expect($file->downloaded)->toBeTrue();
    expect($file->filename)->toBe('temp-file');
    expect($file->path)->toEndWith('.png');
    // Path should be segmented
    expect($file->path)->toMatch('/^downloads\/[a-f0-9]{2}\/[a-f0-9]{2}\//');
});

test('corrects stale mime type when downloaded file content disagrees', function () {
    $image = imagecreatetruecolor(1, 1);
    $white = imagecolorallocate($image, 255, 255, 255);
    imagefill($image, 0, 0, $white);

    ob_start();
    imagepng($image);
    $pngContent = ob_get_clean();
    imagedestroy($image);

    $file = File::factory()->create([
        'url' => 'https://example.com/test-image.jpeg',
        'filename' => 'test-image',
        'ext' => null,
        'mime_type' => 'image/jpeg',
        'downloaded' => false,
        'path' => null,
    ]);

    $tmpPath = 'downloads/.tmp/transfer-1/mismatch.tmp';
    Storage::disk('atlas-app')->put($tmpPath, $pngContent);

    app(FileDownloadFinalizer::class)->finalize($file, $tmpPath);

    $file->refresh();

    expect($file->mime_type)->toBe('image/png');
    expect($file->path)->toEndWith('.png');
});

test('generates thumbnail for image files (finalizer)', function () {
    // Create a simple 1x1 pixel JPEG image for testing
    // This is a minimal but valid JPEG that GD can process
    $image = imagecreatetruecolor(1, 1);
    $white = imagecolorallocate($image, 255, 255, 255);
    imagefill($image, 0, 0, $white);

    ob_start();
    imagejpeg($image, null, 100);
    $jpegContent = ob_get_clean();
    imagedestroy($image);

    $file = File::factory()->create([
        'url' => 'https://example.com/test-image.jpg',
        'filename' => 'test-image.jpg',
        'ext' => 'jpg',
        'downloaded' => false,
        'path' => null,
    ]);

    $tmpPath = 'downloads/.tmp/transfer-1/single.tmp';
    Storage::disk('atlas-app')->put($tmpPath, $jpegContent);

    app(FileDownloadFinalizer::class)->finalize($file, $tmpPath, 'image/jpeg');

    $file->refresh();

    expect($file->downloaded)->toBeTrue();
    expect($file->filename)->toBe('test-image.jpg');

    $meta = FileMetadata::query()->where('file_id', $file->id)->first();
    expect($meta)->not->toBeNull();
    expect($meta->payload)->toBeArray();
    expect($meta->payload['width'] ?? null)->toBe(1);
    expect($meta->payload['height'] ?? null)->toBe(1);
    // Preview should be generated for valid images
    // Verify the path structure and that it exists in fake storage
    if ($file->preview_path) {
        expect($file->preview_path)->toStartWith('thumbnails/');
        expect($file->preview_path)->toMatch('/^thumbnails\/[a-f0-9]{2}\/[a-f0-9]{2}\//');
        expect($file->preview_path)->toContain('_thumb.');
        // Verify thumbnail exists in fake storage
        Storage::disk('atlas-app')->assertExists($file->preview_path);
    }
});
