<?php

use App\Models\File;
use App\Models\FileMetadata;
use App\Services\CivitAiImages;
use Carbon\Carbon;
use Illuminate\Support\Str;

// Fetch-and-optionally-record tests for other services (kept similar to CivitAI)
// Plugin-backed services (wallhaven) are tested in their own packages.

test('persists transformed rows (current shape) [service]', function () {
    $now = Carbon::now();
    $filename = Str::random(40);

    $item = [
        'file' => [
            'source' => 'CivitAI',
            'source_id' => '123',
            'url' => 'https://example.com/path/image-123.jpg?width=512',
            'referrer_url' => 'https://civitai.com/images/123',
            'filename' => $filename,
            'ext' => 'jpg',
            'mime_type' => 'image/jpeg',
            'hash' => null,
            'title' => null,
            'description' => null,
            'thumbnail_url' => 'https://example.com/path/image-123.jpg?width=450',
            'listing_metadata' => json_encode(['id' => 123, 'url' => 'https://example.com/path/image-123.jpg?width=512']),
            'created_at' => $now,
            'updated_at' => $now,
        ],
        'metadata' => [
            'file_referrer_url' => 'https://civitai.com/images/123',
            'payload' => json_encode(['width' => 512, 'height' => 768]),
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ];

    $service = new CivitAiImages;
    $result = $service->persists([$item]);

    expect($result)->toHaveCount(1);
    $file = File::first();
    expect($file)->not()->toBeNull();
    expect($file->referrer_url)->toBe('https://civitai.com/images/123');
    expect(FileMetadata::count())->toBe(1);
    $meta = FileMetadata::first();
    expect($meta->file_id)->toBe($file->id);

    // update payload and ensure upsert updates, not duplicates
    $item['metadata']['payload'] = json_encode(['width' => 1024, 'height' => 768]);
    $service->persists([$item]);
    expect(File::count())->toBe(1);
    expect(FileMetadata::count())->toBe(1);
    $meta->refresh();
    expect($meta->payload['width'])->toBe(1024);
});

test('format civitai response returns rows and nextPage with file/meta structure', function () {
    $fixturePath = base_path('tests/fixtures/civitai-page-1.json');

    $raw = json_decode(file_get_contents($fixturePath), true);
    $formatted = (new CivitAiImages)->transform($raw);

    expect($formatted)->toBeArray()->toHaveKeys(['files', 'filter']);
    expect($formatted['files'])->toBeArray();

    if (! empty($formatted['files'])) {
        $first = $formatted['files'][0];
        expect($first)->toHaveKeys(['file', 'metadata']);
        expect($first['file'])->toHaveKeys([
            'source', 'source_id', 'url', 'referrer_url', 'filename', 'ext', 'mime_type', 'hash', 'title', 'description', 'thumbnail_url', 'listing_metadata', 'created_at', 'updated_at',
        ]);
        expect($first['file']['filename'])->toMatch('/^[A-Za-z0-9]{40}$/');
        expect($first['metadata'])->toHaveKeys(['file_referrer_url', 'payload', 'created_at', 'updated_at']);
        expect($first['file']['created_at'])->toBeInstanceOf(Carbon::class);
        expect($first['metadata']['created_at'])->toBeInstanceOf(Carbon::class);
    }
});

// Plugin-backed services (wallhaven/civit-ai-posts) are tested in their own packages.
