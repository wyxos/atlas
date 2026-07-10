<?php

use App\Jobs\DownloadFile;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/ExtensionApiTestSupport.php';

uses(RefreshDatabase::class);

test('extension video reactions use yt-dlp without a host allowlist', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionApiKey('valid-api-key', $user->id);

    $assetUrl = 'https://media.unlisted-video-host.test/video/HLSPlaylist.m3u8';
    $pageUrl = 'https://media.unlisted-video-host.test/watch/123';

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions', [
        'asset_url' => $assetUrl,
        'metadata' => [
            'asset_type' => 'video',
            'resolution' => '854x480',
        ],
        'referrer_url' => $pageUrl,
        'source' => 'media.unlisted-video-host.test',
        'type' => 'like',
    ]);

    $response->assertCreated();
    $response->assertJsonPath('file.url', $pageUrl);

    $file = File::query()->where('url', $pageUrl)->first();

    expect($file)->not->toBeNull()
        ->and($file?->preview_url)->toBe($assetUrl)
        ->and(data_get($file?->listing_metadata, 'tag_name'))->toBe('video')
        ->and(data_get($file?->listing_metadata, 'download_via'))->toBe('yt-dlp');

    Queue::assertPushed(DownloadFile::class, function (DownloadFile $job) use ($file): bool {
        return $job->fileId === $file?->id && $job->forceDownload === false;
    });
});

test('extension image reactions keep direct download routing', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionApiKey('valid-api-key', $user->id);

    $assetUrl = 'https://cdn.example.test/media/image.jpg';
    $pageUrl = 'https://www.example.test/post/123';

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions', [
        'asset_url' => $assetUrl,
        'metadata' => [
            'asset_type' => 'image',
            'resolution' => '1280x720',
        ],
        'referrer_url' => $pageUrl,
        'source' => 'www.example.test',
        'type' => 'like',
    ]);

    $response->assertCreated();
    $response->assertJsonPath('file.url', $assetUrl);

    $file = File::query()->where('url', $assetUrl)->first();

    expect($file)->not->toBeNull()
        ->and(data_get($file?->listing_metadata, 'tag_name'))->toBe('img')
        ->and(data_get($file?->listing_metadata, 'download_via'))->toBeNull();

    Queue::assertPushed(DownloadFile::class, function (DownloadFile $job) use ($file): bool {
        return $job->fileId === $file?->id && $job->forceDownload === false;
    });
});

test('later extension reactions preserve an established native yt-dlp fallback', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionApiKey('valid-api-key', $user->id);

    $assetUrl = 'https://assets.example.test/video.mp4';
    $pageUrl = 'https://pages.example.test/posts/123';
    $payload = [
        'asset_url' => $assetUrl,
        'metadata' => [
            'asset_type' => 'video',
            'resolution' => '854x480',
        ],
        'referrer_url' => $pageUrl,
        'source' => 'pages.example.test',
        'type' => 'like',
    ];

    $this->withHeaders(['X-Atlas-Api-Key' => 'valid-api-key'])
        ->postJson('/api/extension/reactions', $payload)
        ->assertCreated();

    $file = File::query()->where('url', $pageUrl)->firstOrFail();
    $metadata = $file->listing_metadata;
    unset($metadata['download_via']);
    $metadata['download_via_reason'] = 'yt-dlp-unsupported-native-fallback';
    $file->update(['listing_metadata' => $metadata]);

    $this->withHeaders(['X-Atlas-Api-Key' => 'valid-api-key'])
        ->postJson('/api/extension/reactions', $payload)
        ->assertCreated();

    $file->refresh();

    expect(data_get($file->listing_metadata, 'download_via'))->toBeNull()
        ->and(data_get($file->listing_metadata, 'download_via_reason'))->toBe('yt-dlp-unsupported-native-fallback');
});
