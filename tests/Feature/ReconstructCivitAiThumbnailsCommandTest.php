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
        'original_url' => "https://image.civitai.com/{$token}/{$guid}/original=true/{$id}.mp4",
        'canonical_url' => "https://image.civitai.com/{$token}/{$guid}/transcode=true,original=true,quality=90/{$id}.mp4",
        'canonical_thumb' => "https://image.civitai.com/{$token}/{$guid}/transcode=true,width=450,optimized=true/{$id}.mp4",
    ];
}

it('reconstructs url and thumbnail when listing metadata is missing', function () {
    $meta = civitaiMeta();

    $file = File::factory()->create([
        'source' => 'CivitAI',
        'source_id' => $meta['id'],
        'mime_type' => 'video/mp4',
        'url' => $meta['original_url'],
        'thumbnail_url' => 'https://invalid.local/thumb.mp4',
        'listing_metadata' => null,
    ]);

    Artisan::call('civitai:reconstruct-thumbnails', ['--chunk' => 1]);

    $file->refresh();

    expect($file->url)->toBe($meta['canonical_url'])
        ->and($file->thumbnail_url)->toBe($meta['canonical_thumb']);
});

it('reconstructs from guid stored in listing metadata url string', function () {
    $meta = civitaiMeta();

    $file = File::factory()->create([
        'source' => 'CivitAI',
        'source_id' => $meta['id'],
        'mime_type' => 'video/mp4',
        'url' => null,
        'thumbnail_url' => 'https://invalid.local/thumb.mp4',
        'listing_metadata' => [
            'id' => $meta['id'],
            'url' => $meta['guid'],
            'type' => 'Video',
        ],
    ]);

    Artisan::call('civitai:reconstruct-thumbnails', ['--chunk' => 1]);

    $file->refresh();

    expect($file->url)->toBe($meta['canonical_url'])
        ->and($file->thumbnail_url)->toBe($meta['canonical_thumb']);
});

it('parses canonical data from valid listing url and updates thumbnail', function () {
    $meta = civitaiMeta();

    $file = File::factory()->create([
        'source' => 'CivitAI',
        'source_id' => $meta['id'],
        'mime_type' => 'video/mp4',
        'url' => $meta['original_url'],
        'thumbnail_url' => 'https://invalid.local/thumb.mp4',
        'listing_metadata' => [
            'id' => $meta['id'],
            'url' => $meta['original_url'],
            'type' => 'Video',
        ],
    ]);

    Artisan::call('civitai:reconstruct-thumbnails', ['--chunk' => 1]);

    $file->refresh();

    expect($file->url)->toBe($meta['canonical_url'])
        ->and($file->thumbnail_url)->toBe($meta['canonical_thumb']);
});

it('does not modify files already using canonical values', function () {
    $meta = civitaiMeta();

    $file = File::factory()->create([
        'source' => 'CivitAI',
        'source_id' => $meta['id'],
        'mime_type' => 'video/mp4',
        'url' => $meta['canonical_url'],
        'thumbnail_url' => $meta['canonical_thumb'],
        'listing_metadata' => [
            'id' => $meta['id'],
            'url' => $meta['canonical_url'],
            'type' => 'Video',
        ],
    ]);

    $updatedAt = $file->updated_at;

    Artisan::call('civitai:reconstruct-thumbnails', ['--chunk' => 1]);

    $file->refresh();

    expect($file->url)->toBe($meta['canonical_url'])
        ->and($file->thumbnail_url)->toBe($meta['canonical_thumb'])
        ->and($file->updated_at)->toEqual($updatedAt);
});

it('performs no changes during dry run', function () {
    $meta = civitaiMeta();

    $file = File::factory()->create([
        'source' => 'CivitAI',
        'source_id' => $meta['id'],
        'mime_type' => 'video/mp4',
        'url' => $meta['original_url'],
        'thumbnail_url' => 'https://invalid.local/thumb.mp4',
        'listing_metadata' => [
            'id' => $meta['id'],
            'url' => $meta['guid'],
            'type' => 'Video',
        ],
    ]);

    Artisan::call('civitai:reconstruct-thumbnails', ['--dry-run' => true, '--chunk' => 1]);

    $file->refresh();

    expect($file->url)->toBe($meta['original_url'])
        ->and($file->thumbnail_url)->toBe('https://invalid.local/thumb.mp4');
});
