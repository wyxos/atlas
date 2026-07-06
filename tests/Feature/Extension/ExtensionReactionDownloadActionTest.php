<?php

use App\Enums\DownloadChunkStatus;
use App\Enums\DownloadTransferStatus;
use App\Jobs\DownloadFile;
use App\Jobs\Downloads\PumpDomainDownloads;
use App\Models\DownloadChunk;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/ExtensionApiTestSupport.php';

uses(RefreshDatabase::class);

test('extension batch reactions queue one processing job without synchronously storing files', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionApiKey('valid-api-key', $user->id);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions/batch', [
        'items' => [
            [
                'asset_url' => 'https://images.example.test/deviation/file-1.jpg',
                'metadata' => [
                    'asset_type' => 'image',
                    'resolution' => '1000x1400',
                ],
                'referrer_url' => 'https://www.deviantart.com/artist/art/title-123?file=1',
                'source' => 'www.deviantart.com',
            ],
            [
                'asset_url' => 'https://images.example.test/deviation/file-2.jpg',
                'metadata' => [
                    'asset_type' => 'image',
                    'resolution' => '1200x1600',
                ],
                'referrer_url' => 'https://www.deviantart.com/artist/art/title-123?file=2',
                'source' => 'www.deviantart.com',
            ],
        ],
        'type' => 'love',
    ]);

    $response->assertCreated();
    $response->assertJsonCount(2, 'items');
    $response->assertJsonPath('items.0.asset_url', 'https://images.example.test/deviation/file-1.jpg');
    $response->assertJsonPath('items.1.asset_url', 'https://images.example.test/deviation/file-2.jpg');
    $response->assertJsonPath('items.0.reaction.type', 'love');
    $response->assertJsonPath('items.1.reaction.type', 'love');
    $response->assertJsonPath('items.0.download.requested', true);
    $response->assertJsonPath('items.1.download.requested', true);
    $response->assertJsonPath('items.0.queued', true);
    $response->assertJsonPath('items.1.queued', true);

    expect(File::query()->whereIn('url', [
        'https://images.example.test/deviation/file-1.jpg',
        'https://images.example.test/deviation/file-2.jpg',
    ])->exists())->toBeFalse();

    Queue::assertPushed('App\\Jobs\\ProcessExtensionBatchReaction', function (object $job) use ($user): bool {
        return $job->userId === $user->id
            && $job->reactionType === 'love'
            && $job->downloadBehavior === 'queue'
            && count($job->items) === 2;
    });
    Queue::assertNotPushed(DownloadFile::class);
});

test('queued extension batch reactions store files in bulk and queue positive downloads', function () {
    Queue::fake();

    $user = User::factory()->create();
    $jobClass = 'App\\Jobs\\ProcessExtensionBatchReaction';

    expect(class_exists($jobClass))->toBeTrue();

    $job = new $jobClass(
        userId: (int) $user->id,
        extensionChannel: str_repeat('a', 64),
        items: [
            [
                'asset_url' => 'https://images.example.test/deviation/file-1.jpg',
                'metadata' => [
                    'asset_type' => 'image',
                    'resolution' => '1000x1400',
                ],
                'referrer_url' => 'https://www.deviantart.com/artist/art/title-123?file=1',
                'source' => 'www.deviantart.com',
            ],
            [
                'asset_url' => 'https://images.example.test/deviation/file-2.jpg',
                'metadata' => [
                    'asset_type' => 'image',
                    'resolution' => '1200x1600',
                ],
                'referrer_url' => 'https://www.deviantart.com/artist/art/title-123?file=2',
                'source' => 'www.deviantart.com',
            ],
        ],
        reactionType: 'love',
        downloadBehavior: 'queue',
        runtimeContext: ['user_id' => (int) $user->id],
    );

    DB::enableQueryLog();
    app()->call([$job, 'handle']);
    $reactionWrites = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains(strtolower((string) $query['query']), 'reactions'))
        ->filter(fn (array $query): bool => str_contains(strtolower((string) $query['query']), 'insert'))
        ->count();

    $firstFile = File::query()->where('url', 'https://images.example.test/deviation/file-1.jpg')->first();
    $secondFile = File::query()->where('url', 'https://images.example.test/deviation/file-2.jpg')->first();

    expect($firstFile)->not->toBeNull()
        ->and($secondFile)->not->toBeNull()
        ->and($firstFile?->referrer_url)->toBe('https://www.deviantart.com/artist/art/title-123')
        ->and($secondFile?->referrer_url)->toBe('https://www.deviantart.com/artist/art/title-123?file=2')
        ->and(data_get($firstFile?->listing_metadata, 'extension_user_id'))->toBe($user->id)
        ->and(data_get($secondFile?->listing_metadata, 'extension_user_id'))->toBe($user->id)
        ->and($reactionWrites)->toBe(1);

    expect(Reaction::query()
        ->whereIn('file_id', [$firstFile?->id, $secondFile?->id])
        ->where('user_id', $user->id)
        ->where('type', 'love')
        ->count())->toBe(2);

    Queue::assertPushed(DownloadFile::class, 2);
});

test('queued extension batch reactions can update reactions without queueing downloads', function () {
    Queue::fake();

    $user = User::factory()->create();
    $jobClass = 'App\\Jobs\\ProcessExtensionBatchReaction';

    expect(class_exists($jobClass))->toBeTrue();

    $job = new $jobClass(
        userId: (int) $user->id,
        extensionChannel: str_repeat('c', 64),
        items: [
            [
                'asset_url' => 'https://images.example.test/deviation/update-only-1.jpg',
                'referrer_url' => 'https://www.deviantart.com/artist/art/update-only-123?file=1',
                'source' => 'www.deviantart.com',
            ],
            [
                'asset_url' => 'https://images.example.test/deviation/update-only-2.jpg',
                'referrer_url' => 'https://www.deviantart.com/artist/art/update-only-123?file=2',
                'source' => 'www.deviantart.com',
            ],
        ],
        reactionType: 'like',
        downloadBehavior: 'skip',
        runtimeContext: ['user_id' => (int) $user->id],
    );

    app()->call([$job, 'handle']);

    $fileIds = File::query()
        ->whereIn('url', [
            'https://images.example.test/deviation/update-only-1.jpg',
            'https://images.example.test/deviation/update-only-2.jpg',
        ])
        ->pluck('id')
        ->all();

    expect($fileIds)->toHaveCount(2)
        ->and(Reaction::query()
            ->whereIn('file_id', $fileIds)
            ->where('user_id', $user->id)
            ->where('type', 'like')
            ->count())->toBe(2);

    Queue::assertNotPushed(DownloadFile::class);
});

test('extension reactions can update reactions without queueing downloads', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionApiKey('valid-api-key', $user->id);
    $file = File::factory()->create([
        'url' => 'https://cdn.example.test/media/existing-file.jpg',
    ]);
    Reaction::query()->create([
        'file_id' => $file->id,
        'type' => 'like',
        'user_id' => $user->id,
    ]);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions', [
        'asset_url' => 'https://cdn.example.test/media/existing-file.jpg',
        'download_action' => 'skip',
        'referrer_url' => 'https://www.example.test/post/123',
        'source' => 'example.test',
        'type' => 'love',
    ]);

    $response->assertCreated();
    $response->assertJsonPath('reaction.type', 'love');
    $response->assertJsonPath('download.requested', false);

    expect(Reaction::query()
        ->where('file_id', $file->id)
        ->where('user_id', $user->id)
        ->where('type', 'love')
        ->exists())->toBeTrue();

    Queue::assertNotPushed(DownloadFile::class);
});

test('extension reactions pass runtime cookies and user agent to queued downloads', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionApiKey('valid-api-key', $user->id);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
        'User-Agent' => 'RequestUserAgent/ignored',
    ])->postJson('/api/extension/reactions', [
        'asset_url' => 'https://video.twimg.com/ext_tw_video/example/pu/vid/1280x720/video.mp4',
        'cookies' => [
            [
                'domain' => '.x.com',
                'expires_at' => 1893456000,
                'host_only' => false,
                'http_only' => true,
                'name' => 'auth_token',
                'path' => '/',
                'secure' => true,
                'value' => 'test-token',
            ],
        ],
        'download_action' => 'force',
        'metadata' => [
            'asset_type' => 'video',
        ],
        'referrer_url' => 'https://x.com/example/status/1234567890',
        'source' => 'x.com',
        'type' => 'love',
        'user_agent' => 'AtlasExtensionRuntime/1.0',
    ]);

    $response->assertCreated();

    Queue::assertPushed(DownloadFile::class, function (DownloadFile $job): bool {
        return $job->forceDownload === true
            && data_get($job->runtimeContext, 'cookies.0.name') === 'auth_token'
            && data_get($job->runtimeContext, 'cookies.0.domain') === 'x.com'
            && data_get($job->runtimeContext, 'cookies.0.value') === 'test-token'
            && ($job->runtimeContext['user_agent'] ?? null) === 'AtlasExtensionRuntime/1.0';
    });
});

test('extension batch reactions can update reactions without marking downloads requested', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionApiKey('valid-api-key', $user->id);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions/batch', [
        'download_action' => 'skip',
        'items' => [
            [
                'asset_url' => 'https://images.example.test/deviation/update-only-1.jpg',
                'referrer_url' => 'https://www.deviantart.com/artist/art/update-only-123?file=1',
                'source' => 'www.deviantart.com',
            ],
            [
                'asset_url' => 'https://images.example.test/deviation/update-only-2.jpg',
                'referrer_url' => 'https://www.deviantart.com/artist/art/update-only-123?file=2',
                'source' => 'www.deviantart.com',
            ],
        ],
        'type' => 'like',
    ]);

    $response->assertCreated();
    $response->assertJsonPath('items.0.download.requested', false);
    $response->assertJsonPath('items.1.download.requested', false);

    Queue::assertPushed('App\\Jobs\\ProcessExtensionBatchReaction', function (object $job): bool {
        return $job->reactionType === 'like'
            && $job->downloadBehavior === 'skip'
            && count($job->items) === 2;
    });
    Queue::assertNotPushed(DownloadFile::class);
});

test('extension batch reactions pass runtime cookies and user agent to the queued batch job', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionApiKey('valid-api-key', $user->id);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
        'User-Agent' => 'RequestUserAgent/ignored',
    ])->postJson('/api/extension/reactions/batch', [
        'cookies' => [
            [
                'domain' => '.x.com',
                'expires_at' => 1893456000,
                'host_only' => false,
                'http_only' => true,
                'name' => 'auth_token',
                'path' => '/',
                'secure' => true,
                'value' => 'test-token',
            ],
        ],
        'download_action' => 'force',
        'items' => [
            [
                'asset_url' => 'https://video.twimg.com/ext_tw_video/example/pu/vid/1280x720/video.mp4',
                'metadata' => [
                    'asset_type' => 'video',
                ],
                'referrer_url' => 'https://x.com/example/status/1234567890',
                'source' => 'x.com',
            ],
        ],
        'type' => 'love',
        'user_agent' => 'AtlasExtensionRuntime/1.0',
    ]);

    $response->assertCreated();

    Queue::assertPushed('App\\Jobs\\ProcessExtensionBatchReaction', function (object $job): bool {
        return $job->reactionType === 'love'
            && $job->downloadBehavior === 'force'
            && data_get($job->runtimeContext, 'cookies.0.name') === 'auth_token'
            && data_get($job->runtimeContext, 'cookies.0.domain') === 'x.com'
            && data_get($job->runtimeContext, 'cookies.0.value') === 'test-token'
            && ($job->runtimeContext['user_agent'] ?? null) === 'AtlasExtensionRuntime/1.0';
    });
    Queue::assertNotPushed(DownloadFile::class);
});

test('extension batch reactions can force redownloads for every item', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionApiKey('valid-api-key', $user->id);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions/batch', [
        'download_action' => 'force',
        'items' => [
            [
                'asset_url' => 'https://images.example.test/deviation/redownload-1.jpg',
                'referrer_url' => 'https://www.deviantart.com/artist/art/title-123?file=1',
                'source' => 'www.deviantart.com',
            ],
            [
                'asset_url' => 'https://images.example.test/deviation/redownload-2.jpg',
                'referrer_url' => 'https://www.deviantart.com/artist/art/title-123?file=2',
                'source' => 'www.deviantart.com',
            ],
        ],
        'type' => 'love',
    ]);

    $response->assertCreated();
    $response->assertJsonCount(2, 'items');
    $response->assertJsonPath('items.0.download.requested', true);
    $response->assertJsonPath('items.1.download.requested', true);

    Queue::assertPushed('App\\Jobs\\ProcessExtensionBatchReaction', function (object $job): bool {
        return $job->reactionType === 'love'
            && $job->downloadBehavior === 'force'
            && count($job->items) === 2;
    });
    Queue::assertNotPushed(DownloadFile::class);
});

test('queued extension batch reactions can force redownloads for every item', function () {
    Queue::fake();

    $user = User::factory()->create();
    $jobClass = 'App\\Jobs\\ProcessExtensionBatchReaction';

    expect(class_exists($jobClass))->toBeTrue();

    $job = new $jobClass(
        userId: (int) $user->id,
        extensionChannel: str_repeat('b', 64),
        items: [
            [
                'asset_url' => 'https://images.example.test/deviation/redownload-1.jpg',
                'referrer_url' => 'https://www.deviantart.com/artist/art/title-123?file=1',
                'source' => 'www.deviantart.com',
            ],
            [
                'asset_url' => 'https://images.example.test/deviation/redownload-2.jpg',
                'referrer_url' => 'https://www.deviantart.com/artist/art/title-123?file=2',
                'source' => 'www.deviantart.com',
            ],
        ],
        reactionType: 'love',
        downloadBehavior: 'force',
        runtimeContext: ['user_id' => (int) $user->id],
    );

    app()->call([$job, 'handle']);

    $fileIds = File::query()
        ->whereIn('url', [
            'https://images.example.test/deviation/redownload-1.jpg',
            'https://images.example.test/deviation/redownload-2.jpg',
        ])
        ->pluck('id')
        ->all();

    $jobs = Queue::pushed(DownloadFile::class);

    expect($jobs)->toHaveCount(2);
    expect(collect($jobs)->every(
        fn (DownloadFile $job): bool => in_array($job->fileId, $fileIds, true)
            && $job->forceDownload === true
    ))->toBeTrue();
});

test('extension blacklist cancels an active download transfer', function () {
    Bus::fake();

    $user = User::factory()->create();
    setExtensionApiKey('valid-api-key', $user->id);
    $file = File::factory()->create([
        'url' => 'https://cdn.example.test/media/downloading-file.jpg',
    ]);
    File::query()->whereKey($file->id)->update(['download_progress' => 35]);
    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $file->url,
        'domain' => 'cdn.example.test',
        'status' => DownloadTransferStatus::DOWNLOADING,
        'bytes_total' => 100,
        'bytes_downloaded' => 35,
        'last_broadcast_percent' => 35,
    ]);
    DownloadChunk::query()->create([
        'download_transfer_id' => $transfer->id,
        'index' => 0,
        'range_start' => 0,
        'range_end' => 10,
        'bytes_downloaded' => 11,
        'status' => DownloadChunkStatus::DOWNLOADING,
    ]);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions', [
        'asset_url' => $file->url,
        'referrer_url' => 'https://www.example.test/post/downloading-file',
        'source' => 'example.test',
        'type' => 'blacklist',
    ]);

    $response->assertCreated();
    $response->assertJsonPath('download.requested', false);
    $response->assertJsonPath('download.transfer_id', null);

    $transfer->refresh();
    $file->refresh();

    expect($transfer->status)->toBe(DownloadTransferStatus::CANCELED)
        ->and($file->download_progress)->toBe(0)
        ->and($file->blacklisted_at)->not->toBeNull()
        ->and(DownloadChunk::query()->where('download_transfer_id', $transfer->id)->count())->toBe(0);

    Bus::assertDispatched(PumpDomainDownloads::class, fn (PumpDomainDownloads $job): bool => $job->domain === 'cdn.example.test');
});
