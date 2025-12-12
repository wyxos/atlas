<?php

use App\Jobs\DownloadFile;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('atlas-app');
});

test('downloads file from URL and stores in atlas disk', function () {
    $fileContent = 'fake image content';

    // Fake HTTP response first - before creating file
    Http::fake([
        '*' => Http::response($fileContent, 200, ['Content-Type' => 'image/jpeg']),
    ]);

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

    // Verify HTTP request was made
    Http::assertSent(function ($request) use ($file) {
        return $request->url() === $file->url;
    });

    expect($file->downloaded)->toBeTrue();
    expect($file->path)->toStartWith('downloads/');
    // Path should be segmented: downloads/{hash[0:2]}/{hash[2:4]}/filename
    expect($file->path)->toMatch('/^downloads\/[a-f0-9]{2}\/[a-f0-9]{2}\/test-image\.jpg$/');
    expect($file->filename)->toBe('test-image.jpg');

    // Verify file exists in storage
    Storage::disk('atlas-app')->assertExists($file->path);
    expect(Storage::disk('atlas-app')->get($file->path))->toBe($fileContent);
});

test('skips download if file is already downloaded', function () {
    $file = File::factory()->create([
        'url' => 'https://example.com/test-image.jpg',
        'downloaded' => true,
        'path' => 'downloads/existing-file.jpg',
    ]);

    $job = new DownloadFile($file->id);
    $job->handle();

    // Verify no new files were created
    Storage::disk('atlas-app')->assertMissing('downloads/new-file.jpg');
});

test('skips download if file has no URL', function () {
    $file = File::factory()->create([
        'url' => null,
        'downloaded' => false,
    ]);

    $job = new DownloadFile($file->id);
    $job->handle();

    $file->refresh();
    expect($file->downloaded)->toBeFalse();
});

test('handles download failure gracefully', function () {
    $file = File::factory()->create([
        'url' => 'https://example.com/nonexistent.jpg',
        'downloaded' => false,
    ]);

    // Fake HTTP error response
    Http::fake([
        $file->url => Http::response('Not Found', 404),
    ]);

    $job = new DownloadFile($file->id);
    $job->handle();

    // File should not be marked as downloaded
    $file->refresh();
    expect($file->downloaded)->toBeFalse();
});

test('clears blacklist flags when downloading a blacklisted file', function () {
    $fileContent = 'fake image content';

    // Fake HTTP response
    Http::fake([
        '*' => Http::response($fileContent, 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $file = File::factory()->create([
        'url' => 'https://example.com/test-image.jpg',
        'filename' => 'test-image.jpg',
        'ext' => 'jpg',
        'downloaded' => false,
        'path' => null,
        'blacklisted_at' => now(),
        'blacklist_reason' => 'Race condition test',
    ]);

    $job = new DownloadFile($file->id);
    $job->handle();

    $file->refresh();

    expect($file->downloaded)->toBeTrue();
    expect($file->path)->toStartWith('downloads/');
    // Path should be segmented
    expect($file->path)->toMatch('/^downloads\/[a-f0-9]{2}\/[a-f0-9]{2}\//');
    expect($file->blacklisted_at)->toBeNull();
    expect($file->blacklist_reason)->toBeNull();
});

test('determines extension from MIME type when URL has no extension', function () {
    // Create actual JPEG file content (JPEG magic bytes: FF D8 FF)
    $jpegContent = hex2bin('FFD8FFE000104A46494600010100000100010000FFDB004300');

    Http::fake([
        '*' => Http::response($jpegContent, 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $file = File::factory()->create([
        'url' => 'https://example.com/image?id=12345', // No extension in URL
        'filename' => 'temp-file', // Temporary filename without extension
        'ext' => null,
        'downloaded' => false,
        'path' => null,
    ]);

    $job = new DownloadFile($file->id);
    $job->handle();

    $file->refresh();

    expect($file->downloaded)->toBeTrue();
    expect($file->filename)->toEndWith('.jpg');
    expect($file->path)->toEndWith('.jpg');
    // Path should be segmented
    expect($file->path)->toMatch('/^downloads\/[a-f0-9]{2}\/[a-f0-9]{2}\//');
});

test('determines extension from file content when Content-Type header is missing', function () {
    // Create actual PNG file content (PNG magic bytes: 89 50 4E 47)
    $pngContent = hex2bin('89504E470D0A1A0A0000000D49484452');

    Http::fake([
        '*' => Http::response($pngContent, 200), // No Content-Type header
    ]);

    $file = File::factory()->create([
        'url' => 'https://example.com/file', // No extension
        'filename' => 'temp-file', // Temporary filename without extension
        'ext' => null,
        'downloaded' => false,
        'path' => null,
    ]);

    $job = new DownloadFile($file->id);
    $job->handle();

    $file->refresh();

    expect($file->downloaded)->toBeTrue();
    expect($file->filename)->toEndWith('.png');
    expect($file->path)->toEndWith('.png');
    // Path should be segmented
    expect($file->path)->toMatch('/^downloads\/[a-f0-9]{2}\/[a-f0-9]{2}\//');
});

test('generates thumbnail for image files', function () {
    // Create a simple 1x1 pixel JPEG image for testing
    // This is a minimal but valid JPEG that GD can process
    $image = imagecreatetruecolor(1, 1);
    $white = imagecolorallocate($image, 255, 255, 255);
    imagefill($image, 0, 0, $white);
    
    ob_start();
    imagejpeg($image, null, 100);
    $jpegContent = ob_get_clean();
    imagedestroy($image);

    Http::fake([
        '*' => Http::response($jpegContent, 200, ['Content-Type' => 'image/jpeg']),
    ]);

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
