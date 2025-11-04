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

it('trusts file sniffing when headers report incorrect mime type', function () {
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

    expect($file->mime_type)->toBe('image/jpeg')
        ->and($file->filename)->toBe('bar.jpg')
        ->and($file->path)->toBe('downloads/bar.jpg');

    Storage::disk('atlas_app')->assertExists('downloads/bar.jpg');
    Storage::disk('atlas_app')->assertMissing('downloads/bar.mp4');
});

it('renames video content served behind an image extension', function () {
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

    expect($file->mime_type)->toBe('video/mp4')
        ->and($file->filename)->toBe('baz.mp4')
        ->and($file->path)->toBe('downloads/baz.mp4');

    Storage::disk('atlas_app')->assertExists('downloads/baz.mp4');
    Storage::disk('atlas_app')->assertMissing('downloads/baz.jpg');
});
