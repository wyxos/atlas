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

