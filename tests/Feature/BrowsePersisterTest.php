<?php

use App\Models\File;
use App\Models\FileMetadata;
use App\Services\BrowsePersister;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('returns empty array when given empty transformed items', function () {
    $persister = new BrowsePersister;

    $result = $persister->persist([]);

    expect($result)->toBeArray();
    expect($result)->toBeEmpty();
});

it('filters out items without file and metadata keys', function () {
    $persister = new BrowsePersister;

    $now = now();
    $transformedItems = [
        [
            'file' => [
                'source' => 'CivitAI',
                'source_id' => '1',
                'url' => 'https://example.com/1.jpg',
                'referrer_url' => 'https://example.com/1',
                'filename' => 'test1.jpg',
                'ext' => 'jpg',
                'mime_type' => 'image/jpeg',
                'hash' => null,
                'title' => null,
                'description' => null,
                'thumbnail_url' => null,
                'listing_metadata' => json_encode([]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            'metadata' => [
                'file_referrer_url' => 'https://example.com/1',
                'payload' => json_encode([]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ],
        ['file' => ['referrer_url' => 'https://example.com/2']], // Missing metadata
        ['metadata' => ['payload' => '{}']], // Missing file
        [
            'file' => [
                'source' => 'CivitAI',
                'source_id' => '3',
                'url' => 'https://example.com/3.jpg',
                'referrer_url' => 'https://example.com/3',
                'filename' => 'test3.jpg',
                'ext' => 'jpg',
                'mime_type' => 'image/jpeg',
                'hash' => null,
                'title' => null,
                'description' => null,
                'thumbnail_url' => null,
                'listing_metadata' => json_encode([]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            'metadata' => [
                'file_referrer_url' => 'https://example.com/3',
                'payload' => json_encode([]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ],
    ];

    $result = $persister->persist($transformedItems);

    // Should only persist items with both file and metadata
    expect($result)->toBeArray();
    // The result will be filtered by downloaded/previewed/blacklisted, but we should have persisted 2 files
    expect(File::count())->toBe(2);
});

it('persists files to database using upsert', function () {
    $persister = new BrowsePersister;

    $now = now();
    $transformedItems = [
        [
            'file' => [
                'source' => 'CivitAI',
                'source_id' => '123',
                'url' => 'https://example.com/image1.jpg',
                'referrer_url' => 'https://civitai.com/images/123',
                'filename' => 'test-image-1.jpg',
                'ext' => 'jpg',
                'mime_type' => 'image/jpeg',
                'hash' => 'abc123',
                'title' => null,
                'description' => null,
                'thumbnail_url' => 'https://example.com/thumb1.jpg',
                'listing_metadata' => json_encode(['id' => 123, 'width' => 800, 'height' => 600]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            'metadata' => [
                'file_referrer_url' => 'https://civitai.com/images/123',
                'payload' => json_encode(['width' => 800, 'height' => 600]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ],
    ];

    $result = $persister->persist($transformedItems);

    expect(File::count())->toBe(1);
    $file = File::first();
    expect($file->referrer_url)->toBe('https://civitai.com/images/123');
    expect($file->url)->toBe('https://example.com/image1.jpg');
    expect($file->source)->toBe('CivitAI');
    expect($file->source_id)->toBe('123');
    expect($file->mime_type)->toBe('image/jpeg');
});

it('persists file metadata to database', function () {
    $persister = new BrowsePersister;

    $now = now();
    $transformedItems = [
        [
            'file' => [
                'source' => 'CivitAI',
                'source_id' => '456',
                'url' => 'https://example.com/image2.jpg',
                'referrer_url' => 'https://civitai.com/images/456',
                'filename' => 'test-image-2.jpg',
                'ext' => 'jpg',
                'mime_type' => 'image/jpeg',
                'hash' => null,
                'title' => null,
                'description' => null,
                'thumbnail_url' => 'https://example.com/thumb2.jpg',
                'listing_metadata' => json_encode(['id' => 456]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            'metadata' => [
                'file_referrer_url' => 'https://civitai.com/images/456',
                'payload' => json_encode(['width' => 1024, 'height' => 768, 'custom' => 'data']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ],
    ];

    $persister->persist($transformedItems);

    expect(FileMetadata::count())->toBe(1);
    $metadata = FileMetadata::first();
    expect($metadata->file_id)->toBe(File::first()->id);
    // payload is already decoded as array due to cast
    $payload = $metadata->payload;
    expect($payload['width'])->toBe(1024);
    expect($payload['height'])->toBe(768);
    expect($payload['custom'])->toBe('data');
});

it('updates existing files when same referrer_url is used', function () {
    $persister = new BrowsePersister;

    $now = now();
    $referrerUrl = 'https://civitai.com/images/789';

    // Create initial file
    $file = File::factory()->create([
        'referrer_url' => $referrerUrl,
        'url' => 'https://example.com/old.jpg',
        'mime_type' => 'image/jpeg',
    ]);

    $transformedItems = [
        [
            'file' => [
                'source' => 'CivitAI',
                'source_id' => '789',
                'url' => 'https://example.com/new.jpg', // Updated URL
                'referrer_url' => $referrerUrl, // Same referrer
                'filename' => 'updated-image.jpg',
                'ext' => 'jpg',
                'mime_type' => 'image/png', // Updated MIME type
                'hash' => null,
                'title' => null,
                'description' => null,
                'thumbnail_url' => 'https://example.com/new-thumb.jpg',
                'listing_metadata' => json_encode(['id' => 789]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            'metadata' => [
                'file_referrer_url' => $referrerUrl,
                'payload' => json_encode(['width' => 500, 'height' => 500]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ],
    ];

    $persister->persist($transformedItems);

    expect(File::count())->toBe(1); // Should still be 1 file (updated, not created)
    $updatedFile = File::find($file->id);
    expect($updatedFile->url)->toBe('https://example.com/new.jpg'); // URL should be updated
    expect($updatedFile->mime_type)->toBe('image/png'); // MIME type should be updated
});

it('filters out downloaded files', function () {
    $persister = new BrowsePersister;

    $now = now();
    $transformedItems = [
        [
            'file' => [
                'source' => 'CivitAI',
                'source_id' => '111',
                'url' => 'https://example.com/downloaded.jpg',
                'referrer_url' => 'https://civitai.com/images/111',
                'filename' => 'downloaded.jpg',
                'ext' => 'jpg',
                'mime_type' => 'image/jpeg',
                'hash' => null,
                'title' => null,
                'description' => null,
                'thumbnail_url' => 'https://example.com/thumb.jpg',
                'listing_metadata' => json_encode(['id' => 111]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            'metadata' => [
                'file_referrer_url' => 'https://civitai.com/images/111',
                'payload' => json_encode(['width' => 100, 'height' => 100]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ],
    ];

    $persister->persist($transformedItems);

    // Mark file as downloaded
    $file = File::first();
    $file->downloaded = true;
    $file->save();

    // Persist again - should filter out downloaded file
    $result = $persister->persist($transformedItems);

    expect($result)->toBeEmpty();
});

it('filters out previewed files', function () {
    $persister = new BrowsePersister;

    $now = now();
    $transformedItems = [
        [
            'file' => [
                'source' => 'CivitAI',
                'source_id' => '222',
                'url' => 'https://example.com/previewed.jpg',
                'referrer_url' => 'https://civitai.com/images/222',
                'filename' => 'previewed.jpg',
                'ext' => 'jpg',
                'mime_type' => 'image/jpeg',
                'hash' => null,
                'title' => null,
                'description' => null,
                'thumbnail_url' => 'https://example.com/thumb.jpg',
                'listing_metadata' => json_encode(['id' => 222]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            'metadata' => [
                'file_referrer_url' => 'https://civitai.com/images/222',
                'payload' => json_encode(['width' => 100, 'height' => 100]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ],
    ];

    $persister->persist($transformedItems);

    // Mark file as previewed
    $file = File::first();
    $file->previewed_at = now();
    $file->save();

    // Persist again - should filter out previewed file
    $result = $persister->persist($transformedItems);

    expect($result)->toBeEmpty();
});

it('filters out blacklisted files', function () {
    $persister = new BrowsePersister;

    $now = now();
    $transformedItems = [
        [
            'file' => [
                'source' => 'CivitAI',
                'source_id' => '333',
                'url' => 'https://example.com/blacklisted.jpg',
                'referrer_url' => 'https://civitai.com/images/333',
                'filename' => 'blacklisted.jpg',
                'ext' => 'jpg',
                'mime_type' => 'image/jpeg',
                'hash' => null,
                'title' => null,
                'description' => null,
                'thumbnail_url' => 'https://example.com/thumb.jpg',
                'listing_metadata' => json_encode(['id' => 333]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            'metadata' => [
                'file_referrer_url' => 'https://civitai.com/images/333',
                'payload' => json_encode(['width' => 100, 'height' => 100]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ],
    ];

    $persister->persist($transformedItems);

    // Mark file as blacklisted
    $file = File::first();
    $file->blacklisted_at = now();
    $file->save();

    // Persist again - should filter out blacklisted file
    $result = $persister->persist($transformedItems);

    expect($result)->toBeEmpty();
});

it('returns only files ready for UI consumption', function () {
    $persister = new BrowsePersister;

    $now = now();
    $transformedItems = [
        [
            'file' => [
                'source' => 'CivitAI',
                'source_id' => '444',
                'url' => 'https://example.com/ready.jpg',
                'referrer_url' => 'https://civitai.com/images/444',
                'filename' => 'ready.jpg',
                'ext' => 'jpg',
                'mime_type' => 'image/jpeg',
                'hash' => null,
                'title' => null,
                'description' => null,
                'thumbnail_url' => 'https://example.com/thumb.jpg',
                'listing_metadata' => json_encode(['id' => 444]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            'metadata' => [
                'file_referrer_url' => 'https://civitai.com/images/444',
                'payload' => json_encode(['width' => 100, 'height' => 100]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ],
    ];

    $result = $persister->persist($transformedItems);

    expect($result)->toHaveCount(1);
    expect($result[0])->toBeInstanceOf(File::class);
    expect($result[0]->downloaded)->toBeFalse();
    expect($result[0]->previewed_at)->toBeNull();
    expect($result[0]->blacklisted_at)->toBeNull();
});

it('handles metadata with file_referrer_url from metadata key', function () {
    $persister = new BrowsePersister;

    $now = now();
    $transformedItems = [
        [
            'file' => [
                'source' => 'CivitAI',
                'source_id' => '555',
                'url' => 'https://example.com/image.jpg',
                'referrer_url' => 'https://civitai.com/images/555',
                'filename' => 'image.jpg',
                'ext' => 'jpg',
                'mime_type' => 'image/jpeg',
                'hash' => null,
                'title' => null,
                'description' => null,
                'thumbnail_url' => 'https://example.com/thumb.jpg',
                'listing_metadata' => json_encode(['id' => 555]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            'metadata' => [
                'file_referrer_url' => 'https://civitai.com/images/555', // Same as file referrer
                'payload' => json_encode(['width' => 200, 'height' => 200]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ],
    ];

    $persister->persist($transformedItems);

    // Should use file_referrer_url from metadata if present
    expect(FileMetadata::count())->toBe(1);
    $metadata = FileMetadata::first();
    // The file should be found by the metadata's file_referrer_url
    expect($metadata->file_id)->toBe(File::where('referrer_url', 'https://civitai.com/images/555')->first()->id);
});
