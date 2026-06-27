<?php

use App\Services\BrowsePersister;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses explicit post container source ids when falling back from listing metadata', function () {
    $persister = new BrowsePersister;
    $postUrl = 'https://www.deviantart.com/artist/art/title-123';

    $result = $persister->persist([
        [
            'file' => [
                'referrer_url' => $postUrl.'?file=1',
                'source' => 'extension',
                'url' => 'https://images.example.test/title-123-1.jpg',
                'filename' => 'title-123-1.jpg',
                'ext' => 'jpg',
                'mime_type' => 'image/jpeg',
                'listing_metadata' => json_encode([
                    'post_container_referrer_url' => $postUrl,
                    'post_container_source' => 'deviantart.com',
                    'post_container_source_id' => 'title-123',
                ]),
            ],
            'metadata' => [
                'file_referrer_url' => $postUrl.'?file=1',
                'payload' => json_encode(['test' => 'data']),
            ],
        ],
    ]);

    expect($result)->toHaveCount(1);
    $this->assertDatabaseHas('containers', [
        'type' => 'Post',
        'source' => 'deviantart.com',
        'source_id' => 'title-123',
        'referrer' => $postUrl,
    ]);
    $this->assertDatabaseMissing('containers', [
        'type' => 'Post',
        'source' => 'deviantart.com',
        'source_id' => $postUrl,
    ]);
});
