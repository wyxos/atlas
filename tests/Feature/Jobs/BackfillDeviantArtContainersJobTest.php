<?php

use App\Jobs\BackfillDeviantArtContainers;
use App\Models\Container;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

test('backfill deviantart containers derives user and batch post containers for existing files', function () {
    $batchUrl = 'https://www.deviantart.com/kiririchann/art/Komi-Shuuko-Serving-Drinks-1306906991';
    $singleUrl = 'https://www.deviantart.com/deviantartbender/art/Visionaries-Leoric-Star-Comics-1307302462';
    $sourceUrl = 'https://www.deviantart.com/aipayop/art/Sketchbook-Study-123456789';

    $batchFirst = File::factory()->create([
        'source' => 'extension',
        'url' => 'https://images.example.test/kiririchann-1.jpg',
        'referrer_url' => $batchUrl,
        'listing_metadata' => [
            'page_url' => $batchUrl,
        ],
    ]);

    $batchSecond = File::factory()->create([
        'source' => 'extension',
        'url' => 'https://images.example.test/kiririchann-2.jpg',
        'referrer_url' => $batchUrl.'#image-2',
        'listing_metadata' => [
            'page_url' => $batchUrl,
        ],
    ]);

    $single = File::factory()->create([
        'source' => 'extension',
        'url' => 'https://images.example.test/deviantartbender-1.jpg',
        'referrer_url' => $singleUrl,
        'listing_metadata' => [
            'page_url' => $singleUrl,
        ],
    ]);

    $sourceFile = File::factory()->create([
        'source' => 'deviantart.com',
        'url' => $sourceUrl,
        'referrer_url' => null,
        'listing_metadata' => [],
    ]);

    $nonDeviantArt = File::factory()->create([
        'source' => 'extension',
        'url' => 'https://images.example.test/non-da.jpg',
        'referrer_url' => 'https://www.example.test/post/123',
        'listing_metadata' => [
            'page_url' => 'https://www.example.test/post/123',
        ],
    ]);

    $job = new BackfillDeviantArtContainers(afterId: 0, chunk: 20, queueName: 'processing');
    app()->call([$job, 'handle']);

    $batchFirst->refresh();
    $batchSecond->refresh();
    $single->refresh();
    $sourceFile->refresh();
    $nonDeviantArt->refresh();

    expect(data_get($batchFirst->listing_metadata, 'post_container_referrer_url'))->toBe($batchUrl);
    expect(data_get($batchSecond->listing_metadata, 'post_container_referrer_url'))->toBe($batchUrl);
    expect(data_get($batchFirst->listing_metadata, 'user_container_source_id'))->toBe('kiririchann');
    expect(data_get($batchSecond->listing_metadata, 'user_container_source_id'))->toBe('kiririchann');

    expect(data_get($single->listing_metadata, 'post_container_referrer_url'))->toBeNull();
    expect(data_get($single->listing_metadata, 'user_container_source'))->toBe('deviantart.com');
    expect(data_get($single->listing_metadata, 'user_container_source_id'))->toBe('deviantartbender');
    expect(data_get($single->listing_metadata, 'user_container_referrer_url'))
        ->toBe('https://www.deviantart.com/deviantartbender/gallery');

    expect(data_get($sourceFile->listing_metadata, 'post_container_referrer_url'))->toBeNull();
    expect(data_get($sourceFile->listing_metadata, 'user_container_source'))->toBe('deviantart.com');
    expect(data_get($sourceFile->listing_metadata, 'user_container_source_id'))->toBe('aipayop');
    expect(data_get($sourceFile->listing_metadata, 'user_container_referrer_url'))
        ->toBe('https://www.deviantart.com/aipayop/gallery');

    expect(data_get($nonDeviantArt->listing_metadata, 'post_container_referrer_url'))->toBeNull();
    expect(data_get($nonDeviantArt->listing_metadata, 'user_container_source_id'))->toBeNull();

    $this->assertDatabaseHas('containers', [
        'type' => 'Post',
        'source' => 'deviantart.com',
        'source_id' => $batchUrl,
        'referrer' => $batchUrl,
    ]);

    $this->assertDatabaseHas('containers', [
        'type' => 'User',
        'source' => 'deviantart.com',
        'source_id' => 'kiririchann',
        'referrer' => 'https://www.deviantart.com/kiririchann/gallery',
    ]);

    $this->assertDatabaseHas('containers', [
        'type' => 'User',
        'source' => 'deviantart.com',
        'source_id' => 'deviantartbender',
        'referrer' => 'https://www.deviantart.com/deviantartbender/gallery',
    ]);

    $this->assertDatabaseHas('containers', [
        'type' => 'User',
        'source' => 'deviantart.com',
        'source_id' => 'aipayop',
        'referrer' => 'https://www.deviantart.com/aipayop/gallery',
    ]);

    $this->assertDatabaseMissing('containers', [
        'type' => 'Post',
        'source' => 'deviantart.com',
        'source_id' => $singleUrl,
    ]);

    $postContainer = Container::query()
        ->where('type', 'Post')
        ->where('source', 'deviantart.com')
        ->where('source_id', $batchUrl)
        ->first();

    expect($postContainer)->not->toBeNull();
    expect($batchFirst->containers()->where('containers.id', $postContainer?->id)->exists())->toBeTrue();
    expect($batchSecond->containers()->where('containers.id', $postContainer?->id)->exists())->toBeTrue();
    expect($single->containers()->where('containers.id', $postContainer?->id)->exists())->toBeFalse();
});

test('backfill deviantart containers queues the next chunk when more matching files remain', function () {
    Bus::fake();

    $first = File::factory()->create([
        'source' => 'extension',
        'url' => 'https://images.example.test/next-1.jpg',
        'referrer_url' => 'https://www.deviantart.com/artist-one/art/work-1',
        'listing_metadata' => [
            'page_url' => 'https://www.deviantart.com/artist-one/art/work-1',
        ],
    ]);

    $second = File::factory()->create([
        'source' => 'extension',
        'url' => 'https://images.example.test/next-2.jpg',
        'referrer_url' => 'https://www.deviantart.com/artist-two/art/work-2',
        'listing_metadata' => [
            'page_url' => 'https://www.deviantart.com/artist-two/art/work-2',
        ],
    ]);

    File::factory()->create([
        'source' => 'extension',
        'url' => 'https://images.example.test/next-3.jpg',
        'referrer_url' => 'https://www.deviantart.com/artist-three/art/work-3',
        'listing_metadata' => [
            'page_url' => 'https://www.deviantart.com/artist-three/art/work-3',
        ],
    ]);

    $job = new BackfillDeviantArtContainers(afterId: 0, chunk: 2, queueName: 'maintenance');
    app()->call([$job, 'handle']);

    Bus::assertDispatched(BackfillDeviantArtContainers::class, function (BackfillDeviantArtContainers $job) use ($second): bool {
        return $job->afterId === $second->id
            && $job->chunk === 2
            && $job->queueName === 'maintenance';
    });

    expect($first->fresh()?->containers()->exists())->toBeTrue();
});
