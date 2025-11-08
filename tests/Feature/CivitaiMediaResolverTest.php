<?php

use App\Models\File;
use App\Support\CivitaiMediaResolver;
use Illuminate\Support\Facades\Http;

it('resolves civitai media using thumbnail metadata fallback', function () {
    Http::fake([
        'https://civitai.com/images/104874970' => Http::response('<html><body><h1>404</h1><p>The page you are looking for doesn\'t exist</p></body></html>', 200),
        'https://image.civitai.com/cdn/video-thumb.jpeg' => Http::response('', 200, [
            'Content-Type' => 'video/mp4',
            'Content-Length' => '512000',
        ]),
    ]);

    $file = File::factory()->create([
        'source' => 'CivitAI',
        'thumbnail_url' => 'https://image.civitai.com/cdn/video-thumb.jpeg',
        'url' => 'https://image.civitai.com/poster/original.mp4',
        'mime_type' => 'image/webp',
        'referrer_url' => 'https://civitai.com/images/104874970',
        'listing_metadata' => [
            'url' => 'https://image.civitai.com/poster/original.mp4',
        ],
    ]);

    $resolver = new CivitaiMediaResolver;
    $resolution = $resolver->resolveAndUpdate($file);

    expect($resolution->found)->toBeTrue()
        ->and($resolution->origin)->toBe('metadata')
        ->and($resolution->url)->toBe('https://image.civitai.com/cdn/video-thumb.jpeg');

    $file->refresh();

    expect($file->url)->toBe('https://image.civitai.com/cdn/video-thumb.jpeg')
        ->and($file->mime_type)->toBe('video/mp4')
        ->and($file->not_found)->toBeFalse();
});

it('marks civitai media as not found when all probes fail', function () {
    Http::fake([
        'https://civitai.com/images/2000' => Http::response('<html><body><h1>404 Not Found</h1></body></html>', 200),
        'https://image.civitai.com/poster/2000.mp4' => Http::response('', 404),
        'https://image.civitai.com/thumb/2000.jpeg' => Http::response('', 404),
    ]);

    $file = File::factory()->create([
        'source' => 'CivitAI',
        'thumbnail_url' => 'https://image.civitai.com/thumb/2000.jpeg',
        'url' => 'https://image.civitai.com/poster/2000.mp4',
        'mime_type' => 'image/webp',
        'referrer_url' => 'https://civitai.com/images/2000',
        'listing_metadata' => [
            'url' => 'https://image.civitai.com/poster/2000.mp4',
        ],
    ]);

    $resolver = new CivitaiMediaResolver;
    $resolution = $resolver->resolveAndUpdate($file);

    expect($resolution->found)->toBeFalse()
        ->and($resolution->notFound)->toBeTrue();

    $file->refresh();

    expect($file->not_found)->toBeTrue();
});
