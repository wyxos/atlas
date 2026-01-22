<?php

use App\Models\Container;
use App\Models\File;
use App\Services\BrowsePersister;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates containers for files with postId in listing_metadata', function () {
    $persister = new BrowsePersister;

    $transformedItems = [
        [
            'file' => [
                'referrer_url' => 'https://example.com/file1.jpg',
                'source' => 'CivitAI',
                'url' => 'https://image.civitai.com/file1.jpg',
                'filename' => 'file1.jpg',
                'ext' => 'jpg',
                'mime_type' => 'image/jpeg',
                'listing_metadata' => json_encode([
                    'postId' => 12345,
                ]),
            ],
            'metadata' => [
                'file_referrer_url' => 'https://example.com/file1.jpg',
                'payload' => json_encode(['test' => 'data']),
            ],
        ],
    ];

    $result = $persister->persist($transformedItems);

    expect($result)->toHaveCount(1);
    $this->assertDatabaseHas('containers', [
        'type' => 'Post',
        'source' => 'CivitAI',
        'source_id' => '12345',
        'referrer' => 'https://civitai.com/posts/12345',
    ]);

    $file = File::where('referrer_url', 'https://example.com/file1.jpg')->first();
    $container = Container::where('source_id', '12345')->first();
    expect($file->containers()->where('containers.id', $container->id)->exists())->toBeTrue();
});

it('creates containers for files with username in listing_metadata', function () {
    $persister = new BrowsePersister;

    $transformedItems = [
        [
            'file' => [
                'referrer_url' => 'https://example.com/file2.jpg',
                'source' => 'CivitAI',
                'url' => 'https://image.civitai.com/file2.jpg',
                'filename' => 'file2.jpg',
                'ext' => 'jpg',
                'mime_type' => 'image/jpeg',
                'listing_metadata' => json_encode([
                    'username' => 'testuser',
                ]),
            ],
            'metadata' => [
                'file_referrer_url' => 'https://example.com/file2.jpg',
                'payload' => json_encode(['test' => 'data']),
            ],
        ],
    ];

    $result = $persister->persist($transformedItems);

    expect($result)->toHaveCount(1);
    $this->assertDatabaseHas('containers', [
        'type' => 'User',
        'source' => 'CivitAI',
        'source_id' => 'testuser',
        'referrer' => 'https://civitai.com/user/testuser',
    ]);

    $file = File::where('referrer_url', 'https://example.com/file2.jpg')->first();
    $container = Container::where('source_id', 'testuser')->first();
    expect($file->containers()->where('containers.id', $container->id)->exists())->toBeTrue();
});

it('does not create containers for files with modelId in listing_metadata', function () {
    $persister = new BrowsePersister;

    $transformedItems = [
        [
            'file' => [
                'referrer_url' => 'https://example.com/file-model.jpg',
                'source' => 'CivitAI',
                'url' => 'https://image.civitai.com/file-model.jpg',
                'filename' => 'file-model.jpg',
                'ext' => 'jpg',
                'mime_type' => 'image/jpeg',
                'listing_metadata' => json_encode([
                    'modelId' => 24350,
                    'model' => [
                        'name' => 'PerfectDeliberate',
                    ],
                ]),
            ],
            'metadata' => [
                'file_referrer_url' => 'https://example.com/file-model.jpg',
                'payload' => json_encode(['test' => 'data']),
            ],
        ],
    ];

    $result = $persister->persist($transformedItems);

    expect($result)->toHaveCount(1);
    $this->assertDatabaseCount('containers', 0);

    $file = File::where('referrer_url', 'https://example.com/file-model.jpg')->first();
    expect($file->containers()->count())->toBe(0);
});

it('creates both post and user containers when both are present', function () {
    $persister = new BrowsePersister;

    $transformedItems = [
        [
            'file' => [
                'referrer_url' => 'https://example.com/file3.jpg',
                'source' => 'CivitAI',
                'url' => 'https://image.civitai.com/file3.jpg',
                'filename' => 'file3.jpg',
                'ext' => 'jpg',
                'mime_type' => 'image/jpeg',
                'listing_metadata' => json_encode([
                    'postId' => 999,
                    'username' => 'user999',
                ]),
            ],
            'metadata' => [
                'file_referrer_url' => 'https://example.com/file3.jpg',
                'payload' => json_encode(['test' => 'data']),
            ],
        ],
    ];

    $result = $persister->persist($transformedItems);

    expect($result)->toHaveCount(1);
    $this->assertDatabaseCount('containers', 2);
    $this->assertDatabaseHas('containers', [
        'type' => 'Post',
        'source_id' => '999',
    ]);
    $this->assertDatabaseHas('containers', [
        'type' => 'User',
        'source_id' => 'user999',
    ]);

    $file = File::where('referrer_url', 'https://example.com/file3.jpg')->first();
    expect($file->containers()->count())->toBe(2);
});

it('handles multiple files sharing the same container', function () {
    $persister = new BrowsePersister;

    $postIdMetadata = json_encode(['postId' => 777]);

    $transformedItems = [
        [
            'file' => [
                'referrer_url' => 'https://example.com/file4.jpg',
                'source' => 'CivitAI',
                'url' => 'https://image.civitai.com/file4.jpg',
                'filename' => 'file4.jpg',
                'ext' => 'jpg',
                'mime_type' => 'image/jpeg',
                'listing_metadata' => $postIdMetadata,
            ],
            'metadata' => [
                'file_referrer_url' => 'https://example.com/file4.jpg',
                'payload' => json_encode(['test' => 'data']),
            ],
        ],
        [
            'file' => [
                'referrer_url' => 'https://example.com/file5.jpg',
                'source' => 'CivitAI',
                'url' => 'https://image.civitai.com/file5.jpg',
                'filename' => 'file5.jpg',
                'ext' => 'jpg',
                'mime_type' => 'image/jpeg',
                'listing_metadata' => $postIdMetadata,
            ],
            'metadata' => [
                'file_referrer_url' => 'https://example.com/file5.jpg',
                'payload' => json_encode(['test' => 'data']),
            ],
        ],
    ];

    $result = $persister->persist($transformedItems);

    expect($result)->toHaveCount(2);
    // Should only create one container for the shared postId
    $this->assertDatabaseCount('containers', 1);
    $this->assertDatabaseHas('containers', [
        'type' => 'Post',
        'source_id' => '777',
    ]);

    // Both files should be attached to the same container
    $container = Container::where('source_id', '777')->first();
    $files = File::whereIn('referrer_url', [
        'https://example.com/file4.jpg',
        'https://example.com/file5.jpg',
    ])->get();

    foreach ($files as $file) {
        expect($file->containers()->where('containers.id', $container->id)->exists())->toBeTrue();
    }

    // Should have 2 pivot records (one for each file)
    $this->assertDatabaseCount('container_file', 2);
});

it('does not create containers for files without listing_metadata', function () {
    $persister = new BrowsePersister;

    $transformedItems = [
        [
            'file' => [
                'referrer_url' => 'https://example.com/file6.jpg',
                'source' => 'CivitAI',
                'url' => 'https://image.civitai.com/file6.jpg',
                'filename' => 'file6.jpg',
                'ext' => 'jpg',
                'mime_type' => 'image/jpeg',
                // No listing_metadata
            ],
            'metadata' => [
                'file_referrer_url' => 'https://example.com/file6.jpg',
                'payload' => json_encode(['test' => 'data']),
            ],
        ],
    ];

    $result = $persister->persist($transformedItems);

    expect($result)->toHaveCount(1);
    $this->assertDatabaseCount('containers', 0);
    $this->assertDatabaseCount('container_file', 0);
});

it('does not create containers for files with empty source', function () {
    $persister = new BrowsePersister;

    $transformedItems = [
        [
            'file' => [
                'referrer_url' => 'https://example.com/file7.jpg',
                'source' => '', // Empty source
                'url' => 'https://image.civitai.com/file7.jpg',
                'filename' => 'file7.jpg',
                'ext' => 'jpg',
                'mime_type' => 'image/jpeg',
                'listing_metadata' => json_encode([
                    'postId' => 888,
                ]),
            ],
            'metadata' => [
                'file_referrer_url' => 'https://example.com/file7.jpg',
                'payload' => json_encode(['test' => 'data']),
            ],
        ],
    ];

    $result = $persister->persist($transformedItems);

    expect($result)->toHaveCount(1);
    $this->assertDatabaseCount('containers', 0);
});

it('updates existing containers instead of creating duplicates', function () {
    // Create an existing container
    $existingContainer = Container::create([
        'type' => 'Post',
        'source' => 'CivitAI',
        'source_id' => '555',
        'referrer' => 'https://civitai.com/posts/555',
    ]);

    $persister = new BrowsePersister;

    $transformedItems = [
        [
            'file' => [
                'referrer_url' => 'https://example.com/file8.jpg',
                'source' => 'CivitAI',
                'url' => 'https://image.civitai.com/file8.jpg',
                'filename' => 'file8.jpg',
                'ext' => 'jpg',
                'mime_type' => 'image/jpeg',
                'listing_metadata' => json_encode([
                    'postId' => 555, // Same as existing
                ]),
            ],
            'metadata' => [
                'file_referrer_url' => 'https://example.com/file8.jpg',
                'payload' => json_encode(['test' => 'data']),
            ],
        ],
    ];

    $result = $persister->persist($transformedItems);

    expect($result)->toHaveCount(1);
    // Should still only have one container
    $this->assertDatabaseCount('containers', 1);
    // File should be attached to the existing container
    $file = File::where('referrer_url', 'https://example.com/file8.jpg')->first();
    expect($file->containers()->where('containers.id', $existingContainer->id)->exists())->toBeTrue();
});

it('handles non-civitai sources without referrer', function () {
    $persister = new BrowsePersister;

    $transformedItems = [
        [
            'file' => [
                'referrer_url' => 'https://example.com/file9.jpg',
                'source' => 'OtherSource',
                'url' => 'https://example.com/file9.jpg',
                'filename' => 'file9.jpg',
                'ext' => 'jpg',
                'mime_type' => 'image/jpeg',
                'listing_metadata' => json_encode([
                    'postId' => 111,
                ]),
            ],
            'metadata' => [
                'file_referrer_url' => 'https://example.com/file9.jpg',
                'payload' => json_encode(['test' => 'data']),
            ],
        ],
    ];

    $result = $persister->persist($transformedItems);

    expect($result)->toHaveCount(1);
    $this->assertDatabaseHas('containers', [
        'type' => 'Post',
        'source' => 'OtherSource',
        'source_id' => '111',
        'referrer' => null, // No referrer for non-CivitAI sources
    ]);
});
