<?php

use App\Jobs\DownloadFile;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/ExtensionApiTestSupport.php';

uses(RefreshDatabase::class);

test('extension batch reactions store each file and queue positive downloads', function () {
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

    $firstFile = File::query()->where('url', 'https://images.example.test/deviation/file-1.jpg')->first();
    $secondFile = File::query()->where('url', 'https://images.example.test/deviation/file-2.jpg')->first();

    expect($firstFile)->not->toBeNull()
        ->and($secondFile)->not->toBeNull()
        ->and($firstFile?->referrer_url)->toBe('https://www.deviantart.com/artist/art/title-123?file=1')
        ->and($secondFile?->referrer_url)->toBe('https://www.deviantart.com/artist/art/title-123?file=2')
        ->and(data_get($firstFile?->listing_metadata, 'extension_user_id'))->toBe($user->id)
        ->and(data_get($secondFile?->listing_metadata, 'extension_user_id'))->toBe($user->id);

    expect(Reaction::query()
        ->whereIn('file_id', [$firstFile?->id, $secondFile?->id])
        ->where('user_id', $user->id)
        ->where('type', 'love')
        ->count())->toBe(2);

    Queue::assertPushed(DownloadFile::class, 2);
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
