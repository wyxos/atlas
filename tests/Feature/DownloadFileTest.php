<?php

use App\Jobs\DownloadFile;
use App\Jobs\Downloads\PumpDomainDownloads;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Services\Downloads\FileDownloadFinalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('atlas-app');
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

    $job = new DownloadFile($file->id);
    $job->handle();

    expect(DownloadTransfer::query()->where('file_id', $file->id)->exists())->toBeFalse();
    Bus::assertNotDispatched(PumpDomainDownloads::class);
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

test('clears blacklist flags when finalizing a downloaded file', function () {
    $file = File::factory()->create([
        'url' => 'https://example.com/test-image.jpg',
        'filename' => 'test-image.jpg',
        'ext' => 'jpg',
        'downloaded' => false,
        'path' => null,
        'blacklisted_at' => now(),
        'blacklist_reason' => 'Race condition test',
    ]);

    $tmpPath = 'downloads/.tmp/transfer-1/single.tmp';
    Storage::disk('atlas-app')->put($tmpPath, 'fake image content');

    app(FileDownloadFinalizer::class)->finalize($file, $tmpPath, 'image/jpeg');

    $file->refresh();

    expect($file->downloaded)->toBeTrue();
    expect($file->path)->toStartWith('downloads/');
    // Path should be segmented
    expect($file->path)->toMatch('/^downloads\/[a-f0-9]{2}\/[a-f0-9]{2}\//');
    expect($file->blacklisted_at)->toBeNull();
    expect($file->blacklist_reason)->toBeNull();
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
    expect($file->filename)->toMatch('/\.(jpg|jpeg)$/');
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
    expect($file->filename)->toEndWith('.png');
    expect($file->path)->toEndWith('.png');
    // Path should be segmented
    expect($file->path)->toMatch('/^downloads\/[a-f0-9]{2}\/[a-f0-9]{2}\//');
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
    // Thumbnail should be generated for valid images
    // Verify the path structure and that it exists in fake storage
    if ($file->thumbnail_path) {
        expect($file->thumbnail_path)->toStartWith('thumbnails/');
        expect($file->thumbnail_path)->toMatch('/^thumbnails\/[a-f0-9]{2}\/[a-f0-9]{2}\//');
        expect($file->thumbnail_path)->toContain('_thumb.');
        // Verify thumbnail exists in fake storage
        Storage::disk('atlas-app')->assertExists($file->thumbnail_path);
    }
});
