<?php

use App\Models\Container;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/ExtensionApiTestSupport.php';

uses(RefreshDatabase::class);

test('queued extension batch reactions attach every file to one post container', function () {
    Queue::fake();

    $user = User::factory()->create();
    $jobClass = 'App\\Jobs\\ProcessExtensionBatchReaction';
    $postUrl = 'https://www.deviantart.com/artist/art/title-123';

    expect(class_exists($jobClass))->toBeTrue();

    $job = new $jobClass(
        userId: (int) $user->id,
        extensionChannel: str_repeat('d', 64),
        items: [
            [
                'asset_url' => 'https://images.example.test/deviation/post-container-1.jpg',
                'metadata' => [
                    'asset_type' => 'image',
                    'resolution' => '1000x1400',
                ],
                'referrer_url' => $postUrl.'?file=1',
                'source' => 'www.deviantart.com',
            ],
            [
                'asset_url' => 'https://images.example.test/deviation/post-container-2.jpg',
                'metadata' => [
                    'asset_type' => 'image',
                    'resolution' => '1200x1600',
                ],
                'referrer_url' => $postUrl.'?file=2',
                'source' => 'www.deviantart.com',
            ],
            [
                'asset_url' => 'https://images.example.test/deviation/post-container-3.jpg',
                'metadata' => [
                    'asset_type' => 'image',
                    'resolution' => '900x1200',
                ],
                'referrer_url' => $postUrl.'?file=3',
                'source' => 'www.deviantart.com',
            ],
        ],
        reactionType: 'love',
        downloadBehavior: 'queue',
        runtimeContext: ['user_id' => (int) $user->id],
    );

    app()->call([$job, 'handle']);

    $files = File::query()
        ->whereIn('url', [
            'https://images.example.test/deviation/post-container-1.jpg',
            'https://images.example.test/deviation/post-container-2.jpg',
            'https://images.example.test/deviation/post-container-3.jpg',
        ])
        ->orderBy('url')
        ->get();

    $postContainer = Container::query()
        ->where('type', 'Post')
        ->where('source', 'deviantart.com')
        ->where('source_id', 'title-123')
        ->where('referrer', $postUrl)
        ->first();
    $userContainer = Container::query()
        ->where('type', 'User')
        ->where('source', 'deviantart.com')
        ->where('source_id', 'artist')
        ->where('referrer', 'https://www.deviantart.com/artist/gallery')
        ->first();

    expect($files)->toHaveCount(3)
        ->and($postContainer)->not->toBeNull()
        ->and($userContainer)->not->toBeNull();

    foreach ($files as $file) {
        $expectedReferrerUrl = str_ends_with((string) $file->url, 'post-container-1.jpg')
            ? $postUrl
            : $postUrl.'?file='.match (true) {
                str_ends_with((string) $file->url, 'post-container-2.jpg') => '2',
                default => '3',
            };

        expect($file->referrer_url)->toBe($expectedReferrerUrl)
            ->and(data_get($file->listing_metadata, 'page_url'))->toBe($expectedReferrerUrl)
            ->and(data_get($file->listing_metadata, 'post_container_referrer_url'))->toBe($postUrl)
            ->and(data_get($file->listing_metadata, 'post_container_source'))->toBe('deviantart.com')
            ->and(data_get($file->listing_metadata, 'post_container_source_id'))->toBe('title-123')
            ->and(data_get($file->listing_metadata, 'user_container_source_id'))->toBe('artist')
            ->and(data_get($file->listing_metadata, 'user_container_referrer_url'))->toBe('https://www.deviantart.com/artist/gallery')
            ->and($file->containers()->where('containers.id', $postContainer?->id)->exists())->toBeTrue()
            ->and($file->containers()->where('containers.id', $userContainer?->id)->exists())->toBeTrue();
    }
});

test('extension reactions attach DeviantArt post and user containers', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionApiKey('valid-api-key', $user->id);
    $postUrl = 'https://www.deviantart.com/artist/art/title-123';

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions', [
        'asset_url' => 'https://images.example.test/deviation/single-container.jpg',
        'metadata' => [
            'asset_type' => 'image',
            'resolution' => '1000x1400',
        ],
        'referrer_url' => $postUrl.'?file=1',
        'source' => 'www.deviantart.com',
        'type' => 'love',
    ]);

    $response->assertCreated();

    $file = File::query()
        ->where('url', 'https://images.example.test/deviation/single-container.jpg')
        ->first();
    $postContainer = Container::query()
        ->where('type', 'Post')
        ->where('source', 'deviantart.com')
        ->where('source_id', 'title-123')
        ->where('referrer', $postUrl)
        ->first();
    $userContainer = Container::query()
        ->where('type', 'User')
        ->where('source', 'deviantart.com')
        ->where('source_id', 'artist')
        ->where('referrer', 'https://www.deviantart.com/artist/gallery')
        ->first();

    expect($file)->not->toBeNull()
        ->and($postContainer)->not->toBeNull()
        ->and($userContainer)->not->toBeNull()
        ->and($file?->referrer_url)->toBe($postUrl)
        ->and(data_get($file?->listing_metadata, 'page_url'))->toBe($postUrl)
        ->and(data_get($file?->listing_metadata, 'post_container_source'))->toBe('deviantart.com')
        ->and(data_get($file?->listing_metadata, 'post_container_source_id'))->toBe('title-123')
        ->and(data_get($file?->listing_metadata, 'post_container_referrer_url'))->toBe($postUrl)
        ->and(data_get($file?->listing_metadata, 'user_container_source_id'))->toBe('artist')
        ->and(data_get($file?->listing_metadata, 'user_container_referrer_url'))->toBe('https://www.deviantart.com/artist/gallery')
        ->and($file?->containers()->where('containers.id', $postContainer?->id)->exists())->toBeTrue()
        ->and($file?->containers()->where('containers.id', $userContainer?->id)->exists())->toBeTrue();
});

test('extension reactions attach DeviantArt user containers without post containers for single item deviations', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionApiKey('valid-api-key', $user->id);
    $postUrl = 'https://www.deviantart.com/artist/art/single-123';

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions', [
        'asset_url' => 'https://images.example.test/deviation/single-item.jpg',
        'metadata' => [
            'asset_type' => 'image',
            'resolution' => '1000x1400',
        ],
        'referrer_url' => $postUrl,
        'source' => 'www.deviantart.com',
        'type' => 'love',
    ]);

    $response->assertCreated();

    $file = File::query()
        ->where('url', 'https://images.example.test/deviation/single-item.jpg')
        ->first();
    $userContainer = Container::query()
        ->where('type', 'User')
        ->where('source', 'deviantart.com')
        ->where('source_id', 'artist')
        ->where('referrer', 'https://www.deviantart.com/artist/gallery')
        ->first();

    expect($file)->not->toBeNull()
        ->and($userContainer)->not->toBeNull()
        ->and(data_get($file?->listing_metadata, 'user_container_source_id'))->toBe('artist')
        ->and(data_get($file?->listing_metadata, 'post_container_source_id'))->toBeNull()
        ->and(data_get($file?->listing_metadata, 'post_container_referrer_url'))->toBeNull()
        ->and($file?->containers()->where('containers.id', $userContainer?->id)->exists())->toBeTrue()
        ->and($file?->containers()->where('containers.type', 'Post')->exists())->toBeFalse();

});
