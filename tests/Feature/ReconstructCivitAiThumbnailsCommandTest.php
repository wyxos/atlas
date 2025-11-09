<?php

use App\Models\File;
use Illuminate\Support\Facades\Artisan;

function civitaiMeta(): array
{
    $token = 'xG1nkqKTMzGDvpLrqFT7WA';
    $guid = 'd98899f5-b9e8-44b8-9df2-2ee685de18cd';
    $id = 81113576;

    return [
        'id' => $id,
        'token' => $token,
        'guid' => $guid,
        'remote_url' => "https://image.civitai.com/{$token}/{$guid}/original=true/{$id}.mp4",
        'expected_remote_url' => "https://image.civitai.com/{$token}/{$guid}/transcode=true,original=true,quality=90/{$id}.mp4",
        'expected_thumb_mp4' => "https://image.civitai.com/{$token}/{$guid}/transcode=true,width=450,optimized=true/{$id}.mp4",
    ];
}

it('reconstructs civitai video thumbnails', function () {
    $meta = civitaiMeta();

    $file = File::factory()->create([
        'source' => 'CivitAI',
        'source_id' => $meta['id'],
        'mime_type' => 'video/mp4',
        'url' => $meta['remote_url'],
        'thumbnail_url' => 'https://invalid.local/placeholder.mp4',
        'listing_metadata' => [
            'id' => $meta['id'],
            'url' => $meta['remote_url'],
            'type' => 'Video',
        ],
    ]);

    Artisan::call('civitai:reconstruct-thumbnails', ['--chunk' => 1]);

    $file->refresh();

    expect($file->thumbnail_url)->toBe($meta['expected_thumb_mp4']);
});

it('supports dry run without persisting changes', function () {
    $meta = civitaiMeta();

    $file = File::factory()->create([
        'source' => 'CivitAI',
        'source_id' => $meta['id'],
        'mime_type' => 'video/mp4',
        'url' => $meta['remote_url'],
        'thumbnail_url' => 'https://invalid.local/placeholder.mp4',
        'listing_metadata' => [
            'id' => $meta['id'],
            'url' => $meta['remote_url'],
            'type' => 'Video',
        ],
    ]);

    Artisan::call('civitai:reconstruct-thumbnails', ['--dry-run' => true, '--chunk' => 1]);

    $file->refresh();

    expect($file->thumbnail_url)->toBe('https://invalid.local/placeholder.mp4');

    $output = Artisan::output();
    expect($output)->toContain('Dry run complete');
});

it('reconstructs guid-based listing metadata', function () {
    $meta = civitaiMeta();

    $file = File::factory()->create([
        'source' => 'CivitAI',
        'source_id' => $meta['id'],
        'mime_type' => 'video/mp4',
        'url' => null,
        'thumbnail_url' => $meta['expected_thumb_mp4'],
        'listing_metadata' => [
            'id' => $meta['id'],
            'url' => $meta['guid'],
            'type' => 'Video',
        ],
    ]);

    Artisan::call('civitai:reconstruct-thumbnails', ['--chunk' => 1]);

    $file->refresh();

    expect($file->url)->toBe($meta['expected_remote_url'])
        ->and($file->listing_metadata['url'])->toBe($meta['expected_remote_url'])
        ->and($file->listing_metadata['guid'])->toBe($meta['guid'])
        ->and($file->thumbnail_url)->toBe($meta['expected_thumb_mp4']);
});

