<?php

use App\Enums\DownloadTransferStatus;
use App\Jobs\DownloadFile;
use App\Jobs\SyncLibraryIndex;
use App\Models\Container;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/ExtensionReactionsTestSupport.php';

uses(RefreshDatabase::class);

test('extension batch reactions queue all submitted gallery items and return the selected primary item', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionReactionApiKey('valid-api-key', $user->id);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions/batch', [
        'type' => 'like',
        'primary_candidate_id' => 'image-2',
        'items' => [
            [
                'candidate_id' => 'image-1',
                'url' => 'https://images.example.test/direct-image-1.jpg',
                'referrer_url_hash_aware' => 'https://www.deviantart.com/artist/art/post-1',
                'page_url' => 'https://www.deviantart.com/artist/art/post-1',
                'tag_name' => 'img',
            ],
            [
                'candidate_id' => 'image-2',
                'url' => 'https://images.example.test/direct-image-2.jpg',
                'referrer_url_hash_aware' => 'https://www.deviantart.com/artist/art/post-1#image-2',
                'page_url' => 'https://www.deviantart.com/artist/art/post-1',
                'tag_name' => 'img',
            ],
        ],
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('file.url', 'https://images.example.test/direct-image-2.jpg');
    $response->assertJsonPath('file.referrer_url', 'https://www.deviantart.com/artist/art/post-1#image-2');
    $response->assertJsonPath('batch.count', 2);
    $response->assertJsonPath('batch.primary_candidate_id', 'image-2');
    $response->assertJsonPath('batch.download_requested', true);

    $firstFile = File::query()->where('url', 'https://images.example.test/direct-image-1.jpg')->first();
    $secondFile = File::query()->where('url', 'https://images.example.test/direct-image-2.jpg')->first();

    expect($firstFile)->not->toBeNull();
    expect($secondFile)->not->toBeNull();
    expect($firstFile?->source)->toBe('deviantart.com');
    expect($secondFile?->source)->toBe('deviantart.com');
    expect($firstFile?->referrer_url)->toBe('https://www.deviantart.com/artist/art/post-1');
    expect($secondFile?->referrer_url)->toBe('https://www.deviantart.com/artist/art/post-1#image-2');
    expect(data_get($firstFile?->listing_metadata, 'post_container_referrer_url'))
        ->toBe('https://www.deviantart.com/artist/art/post-1');
    expect(data_get($secondFile?->listing_metadata, 'post_container_referrer_url'))
        ->toBe('https://www.deviantart.com/artist/art/post-1');
    expect(data_get($firstFile?->listing_metadata, 'post_container_source'))
        ->toBe('deviantart.com');
    expect(data_get($secondFile?->listing_metadata, 'post_container_source'))
        ->toBe('deviantart.com');
    expect(data_get($firstFile?->listing_metadata, 'user_container_source'))
        ->toBe('deviantart.com');
    expect(data_get($secondFile?->listing_metadata, 'user_container_source'))
        ->toBe('deviantart.com');
    expect(data_get($firstFile?->listing_metadata, 'user_container_source_id'))
        ->toBe('artist');
    expect(data_get($secondFile?->listing_metadata, 'user_container_source_id'))
        ->toBe('artist');
    expect(data_get($firstFile?->listing_metadata, 'user_container_referrer_url'))
        ->toBe('https://www.deviantart.com/artist/gallery');
    expect(data_get($secondFile?->listing_metadata, 'user_container_referrer_url'))
        ->toBe('https://www.deviantart.com/artist/gallery');

    expect(Reaction::query()
        ->where('user_id', $user->id)
        ->where('file_id', $firstFile?->id)
        ->value('type'))->toBe('like');
    expect(Reaction::query()
        ->where('user_id', $user->id)
        ->where('file_id', $secondFile?->id)
        ->value('type'))->toBe('like');

    $this->assertDatabaseHas('containers', [
        'type' => 'User',
        'source' => 'deviantart.com',
        'source_id' => 'artist',
        'referrer' => 'https://www.deviantart.com/artist/gallery',
    ]);

    $container = Container::query()
        ->where('type', 'User')
        ->where('source', 'deviantart.com')
        ->where('source_id', 'artist')
        ->first();

    expect($container)->not->toBeNull();
    expect($firstFile?->containers()->where('containers.id', $container?->id)->exists())->toBeTrue();
    expect($secondFile?->containers()->where('containers.id', $container?->id)->exists())->toBeTrue();

    Queue::assertPushed(DownloadFile::class, 2);
    Queue::assertPushed(
        SyncLibraryIndex::class,
        fn (SyncLibraryIndex $job): bool => $job->syncFiles
            && $job->syncReactions
            && $job->fileIds === [$firstFile?->id, $secondFile?->id],
    );
    Queue::assertPushed(SyncLibraryIndex::class, 1);
});

test('extension batch reactions hydrate active transfers with one bulk lookup', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionReactionApiKey('valid-api-key', $user->id);

    $firstFile = File::factory()->create([
        'source' => 'deviantart.com',
        'url' => 'https://images.example.test/active-transfer-image-1.jpg',
        'referrer_url' => 'https://www.deviantart.com/artist/art/active-transfer-1',
    ]);
    $secondFile = File::factory()->create([
        'source' => 'deviantart.com',
        'url' => 'https://images.example.test/active-transfer-image-2.jpg',
        'referrer_url' => 'https://www.deviantart.com/artist/art/active-transfer-2',
    ]);

    DownloadTransfer::query()->create([
        'file_id' => $firstFile->id,
        'url' => $firstFile->url,
        'domain' => 'images.example.test',
        'status' => DownloadTransferStatus::PENDING,
        'last_broadcast_percent' => 12,
    ]);
    $firstActiveTransfer = DownloadTransfer::query()->create([
        'file_id' => $firstFile->id,
        'url' => $firstFile->url,
        'domain' => 'images.example.test',
        'status' => DownloadTransferStatus::DOWNLOADING,
        'last_broadcast_percent' => 45,
    ]);
    DownloadTransfer::query()->create([
        'file_id' => $firstFile->id,
        'url' => $firstFile->url,
        'domain' => 'images.example.test',
        'status' => DownloadTransferStatus::COMPLETED,
        'last_broadcast_percent' => 100,
    ]);
    $secondActiveTransfer = DownloadTransfer::query()->create([
        'file_id' => $secondFile->id,
        'url' => $secondFile->url,
        'domain' => 'images.example.test',
        'status' => DownloadTransferStatus::QUEUED,
        'last_broadcast_percent' => 0,
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions/batch', [
        'type' => 'like',
        'download_behavior' => 'skip',
        'primary_candidate_id' => 'image-2',
        'items' => [
            [
                'candidate_id' => 'image-1',
                'url' => $firstFile->url,
                'referrer_url_hash_aware' => $firstFile->referrer_url,
                'page_url' => $firstFile->referrer_url,
                'tag_name' => 'img',
            ],
            [
                'candidate_id' => 'image-2',
                'url' => $secondFile->url,
                'referrer_url_hash_aware' => $secondFile->referrer_url,
                'page_url' => $secondFile->referrer_url,
                'tag_name' => 'img',
            ],
        ],
    ]);

    $downloadTransferQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains(
            strtolower((string) $query['query']),
            'download_transfers',
        ))
        ->values();

    DB::disableQueryLog();

    $response->assertSuccessful();
    $response->assertJsonPath('download.transfer_id', $secondActiveTransfer->id);
    $response->assertJsonPath('download.status', DownloadTransferStatus::QUEUED);
    $response->assertJsonPath('batch.items.0.download.transfer_id', $firstActiveTransfer->id);
    $response->assertJsonPath('batch.items.0.download.status', DownloadTransferStatus::DOWNLOADING);
    $response->assertJsonPath('batch.items.0.download.progress_percent', 45);
    $response->assertJsonPath('batch.items.1.download.transfer_id', $secondActiveTransfer->id);

    expect($downloadTransferQueries)->toHaveCount(1);
});

test('extension batch reactions create a user container for deviantart gallery urls', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionReactionApiKey('valid-api-key', $user->id);

    $galleryUrl = 'https://www.deviantart.com/aipayop/gallery';

    $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions/batch', [
        'type' => 'like',
        'primary_candidate_id' => 'image-1',
        'items' => [
            [
                'candidate_id' => 'image-1',
                'url' => 'https://images.example.test/gallery-image-1.jpg',
                'referrer_url_hash_aware' => $galleryUrl,
                'page_url' => $galleryUrl,
                'tag_name' => 'img',
            ],
            [
                'candidate_id' => 'image-2',
                'url' => 'https://images.example.test/gallery-image-2.jpg',
                'referrer_url_hash_aware' => $galleryUrl,
                'page_url' => $galleryUrl,
                'tag_name' => 'img',
            ],
        ],
    ])->assertSuccessful();

    $this->assertDatabaseHas('containers', [
        'type' => 'User',
        'source' => 'deviantart.com',
        'source_id' => 'aipayop',
        'referrer' => 'https://www.deviantart.com/aipayop/gallery',
    ]);
});

test('extension batch reactions do not create post or user containers for non-deviantart urls', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionReactionApiKey('valid-api-key', $user->id);

    $postUrl = 'https://www.example.test/post/123';

    $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions/batch', [
        'type' => 'like',
        'primary_candidate_id' => 'image-1',
        'items' => [
            [
                'candidate_id' => 'image-1',
                'url' => 'https://images.example.test/non-da-image-1.jpg',
                'referrer_url_hash_aware' => $postUrl,
                'page_url' => $postUrl,
                'tag_name' => 'img',
            ],
            [
                'candidate_id' => 'image-2',
                'url' => 'https://images.example.test/non-da-image-2.jpg',
                'referrer_url_hash_aware' => $postUrl,
                'page_url' => $postUrl,
                'tag_name' => 'img',
            ],
        ],
    ])->assertSuccessful();

    $this->assertDatabaseCount('containers', 0);
});
