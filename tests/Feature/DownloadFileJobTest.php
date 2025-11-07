<?php

use App\Jobs\DownloadFile;
use App\Models\Download;
use App\Models\File;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('corrects civitai media type when downloading mislabeled content', function () {
    Storage::fake('atlas_app');

    $webpBody = base64_decode('UklGRiQAAABXRUJQVlA4ICQAAAAQAAAAHAAAAABwAQCdASoIAAgAAkA4JaQAA3AA/vuUAAA=');

    Http::fake(function (Request $request) use ($webpBody) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 200, [
                'Content-Type' => 'image/webp',
                'Accept-Ranges' => 'none',
            ]);
        }

        return Http::response($webpBody, 200, [
            'Content-Type' => 'image/webp',
            'Content-Length' => strlen($webpBody),
        ]);
    });

    $file = File::factory()->create([
        'url' => 'https://image.civitai.com/foo.mp4',
        'filename' => 'foo.mp4',
        'source' => 'CivitAI',
        'mime_type' => 'video/mp4',
        'downloaded' => false,
        'download_progress' => 0,
    ]);

    (new DownloadFile($file))->handle();

    $file->refresh();

    expect($file->filename)->toBe('foo.webp')
        ->and($file->path)->toBe('downloads/foo.webp')
        ->and($file->mime_type)->toBe('image/webp')
        ->and($file->downloaded)->toBeTrue();

    Storage::disk('atlas_app')->assertExists('downloads/foo.webp');
    Storage::disk('atlas_app')->assertMissing('downloads/foo.mp4');

    $download = Download::first();
    expect($download)->not->toBeNull()
        ->and($download->status)->toBe('completed');
});

it('trusts headers when content sniffing is disabled', function () {
    Storage::fake('atlas_app');

    $jpegBody = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAkGBxISEhUTEhIVFhUXFxgYGBgXFxgbGhwYFxgYGBgaGhgYHSggGB0lGxcYITEhJSkrLi4uGCAzODMtNygtLisBCgoKDg0OGxAQGy0mICYtLS8tLS0tLy0tLS0tLS0tLS0tLS0tLS8tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLf/AABEIAKAAoAMBIgACEQEDEQH/xAAbAAEAAgMBAQAAAAAAAAAAAAAABQYCAwQBB//EAD4QAAIBAwMBBgQDBgYDAAAAAAECAwAEERIhBTFBEyJRYXEGMoGRobHB8BQjQlJy0fAUIyQzU3KCk6Ky4fEVJDTh8RY0Q2P/xAAaAQEBAQEBAQEAAAAAAAAAAAAAAQIDBAUG/8QAKxEAAgICAQMDBAMBAAAAAAAAAAECEQMhEjEEQRMiUWEFcYGRobHB8PFC/9oADAMBAAIRAxEAPwD9i0sSBJpBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pCt0gIpBBJ4pAl0gJJBBJ4pAt0gJpBBJ4pAl0gJJBBJ4pAt0gJpBBJ4pAl0gJJBBJ4pAt0gJpBBJ4pAl0gJJBBJ4pAt0gJpBBJ4pAl0gJJBBJ4pAt0gJpBBJ4pAl0gJJBBJ4pAt0gJpBBJ4pAl0gJJBBJ4pAt0gJpBBJ4pAl0gJJBBJ4pAt0gJpBBJ4pAl0gJJBBJ4pAt0gJpBBJ4pAl0gJJBBJ4pAt0gJpBBJ4pAl0gJJBBJ4pAt0gJpBBJ4pAl0gJJBBJ4pAt0gJpBBJ4pAl0gJJBBJ4pAt0gJpBBJ4pAl0gJJBBJ4pAt0gJpBBJ4pAl0gJJBBJ4pAt0gJpBBJ4pAl0gJJBBJ4pAt0gJpBBJ4pAl0gJJBBJ4pCt0gIpBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pAt0gJpBBI4pAl0gJJBBJ4pCt0gIpBBI4pAt0gJpBBJ4pAl0gJJBBJ4pAt0gJpBBJ4pAl0gJJBBJ4pAt0gJpBBJ4pAl0gJJBBJ4pAt0gJpBBJ4pAl0gJJBBJ4pAt0gJpBBJ4pAl0gJJBBJ4pAt0gJpBBJ4pAl0gJJBBJ4pAt0gJpBBJ4pAl0gJJBBJ4pAt0gJpBBJ4pAl0gJJBBJ4pAt0gJpBBJ4pAl0gJJBBJ4pAt0gJpBBJ4pAl0gJJBBJ4pAt0gJpBBJ4pAl0gJJBBJ4pAt0gJpBBJ4pAl0gJJBBJ4pAt0gJpBBJ4pAl0gJJBBJ4pAt0gJpBBJ4pAt0gJJBBJ4pAt0gJpBBI4pAl0gI3/9k=');

    Http::fake(function (Request $request) use ($jpegBody) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 200, [
                'Content-Type' => 'video/mp4',
                'Accept-Ranges' => 'none',
            ]);
        }

        return Http::response($jpegBody, 200, [
            'Content-Type' => 'video/mp4',
            'Content-Length' => strlen($jpegBody),
        ]);
    });

    $file = File::factory()->create([
        'url' => 'https://image.civitai.com/bar.mp4',
        'filename' => 'bar.mp4',
        'source' => 'CivitAI',
        'mime_type' => 'video/mp4',
        'downloaded' => false,
        'download_progress' => 0,
    ]);

    (new DownloadFile($file))->handle();

    $file->refresh();

    // Content sniffing is disabled, so we trust the headers
    expect($file->mime_type)->toBe('video/mp4')
        ->and($file->filename)->toBe('bar.mp4')
        ->and($file->path)->toBe('downloads/bar.mp4');

    Storage::disk('atlas_app')->assertExists('downloads/bar.mp4');
});

it('trusts headers when content sniffing is disabled for image extension', function () {
    Storage::fake('atlas_app');

    $mp4Body = hex2bin('000000186674797069736f6d0000020069736f6d6d7034310000000866726565');

    Http::fake(function (Request $request) use ($mp4Body) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 200, [
                'Content-Type' => 'image/jpeg',
                'Accept-Ranges' => 'none',
            ]);
        }

        return Http::response($mp4Body, 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => strlen($mp4Body),
        ]);
    });

    $file = File::factory()->create([
        'url' => 'https://image.civitai.com/baz.jpg',
        'filename' => 'baz.jpg',
        'source' => 'CivitAI',
        'mime_type' => 'image/jpeg',
        'downloaded' => false,
        'download_progress' => 0,
    ]);

    (new DownloadFile($file))->handle();

    $file->refresh();

    // Content sniffing is disabled, so we trust the headers
    expect($file->mime_type)->toBe('image/jpeg')
        ->and($file->filename)->toBe('baz.jpg')
        ->and($file->path)->toBe('downloads/baz.jpg');

    Storage::disk('atlas_app')->assertExists('downloads/baz.jpg');
});

it('uses thumbnail_url as fallback when referrer URL returns 404 during download', function () {
    Storage::fake('atlas_app');

    $webpBody = base64_decode('UklGRiQAAABXRUJQVlA4ICQAAAAQAAAAHAAAAABwAQCdASoIAAgAAkA4JaQAA3AA/vuUAAA=');
    $mp4Body = hex2bin('000000186674797069736f6d0000020069736f6d6d7034310000000866726565');
    $referrerUrl = 'https://civitai.com/images/123456';
    $thumbnailUrl = 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/96b33e41-8a73-4faa-8ba2-02f65e80dc35/original=true/video.mp4';

    Http::fake(function (Request $request) use ($webpBody, $mp4Body, $referrerUrl, $thumbnailUrl) {
        if ($request->method() === 'HEAD' && $request->url() === $thumbnailUrl) {
            return Http::response('', 200, [
                'Content-Type' => 'video/mp4',
                'Accept-Ranges' => 'none',
                'Content-Length' => strlen($mp4Body),
            ]);
        }

        if ($request->url() === $referrerUrl) {
            return Http::response('Not Found', 404);
        }

        if ($request->url() === $thumbnailUrl) {
            return Http::response($mp4Body, 200, [
                'Content-Type' => 'video/mp4',
                'Content-Length' => strlen($mp4Body),
            ]);
        }

        // Original URL returns webp
        if ($request->method() === 'HEAD') {
            return Http::response('', 200, [
                'Content-Type' => 'image/webp',
                'Accept-Ranges' => 'none',
            ]);
        }

        return Http::response($webpBody, 200, [
            'Content-Type' => 'image/webp',
            'Content-Length' => strlen($webpBody),
        ]);
    });

    $file = File::factory()->create([
        'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/96b33e41-8a73-4faa-8ba2-02f65e80dc35/original=true/poster.mp4',
        'filename' => 'poster.mp4',
        'source' => 'CivitAI',
        'referrer_url' => $referrerUrl,
        'thumbnail_url' => $thumbnailUrl,
        'mime_type' => 'video/mp4',
        'downloaded' => false,
        'download_progress' => 0,
    ]);

    (new DownloadFile($file))->handle();

    $file->refresh();

    expect($file->url)->toBe($thumbnailUrl)
        ->and($file->mime_type)->toBe('video/mp4')
        ->and($file->filename)->toBe('poster.mp4')
        ->and($file->path)->toBe('downloads/poster.mp4')
        ->and($file->downloaded)->toBeTrue();

    Storage::disk('atlas_app')->assertExists('downloads/poster.mp4');
});
