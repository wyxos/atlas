<?php

use App\Jobs\DownloadFile;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/ExtensionReactionsTestSupport.php';

uses(RefreshDatabase::class);

test('extension reactions endpoint requires a valid api key', function () {
    setExtensionReactionApiKey('valid-api-key');

    $response = $this->postJson('/api/extension/reactions', [
        'type' => 'like',
        'url' => 'https://cdn.example.test/media/new-file.jpg',
    ]);

    $response->assertUnauthorized();
});

test('extension reactions endpoint responds to extension cors preflight and actual requests', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionReactionApiKey('valid-api-key', $user->id);

    $origin = 'chrome-extension://dhhmiflbhoaffjmlfpihmpioflgocekg';

    $preflightResponse = $this->withHeaders([
        'Origin' => $origin,
        'Access-Control-Request-Method' => 'POST',
        'Access-Control-Request-Headers' => 'content-type,x-atlas-api-key',
    ])->options('/api/extension/reactions');

    $preflightResponse->assertNoContent();
    $preflightResponse->assertHeader('Access-Control-Allow-Origin', $origin);
    $preflightResponse->assertHeader('Access-Control-Allow-Methods', 'POST');
    $preflightResponse->assertHeader('Access-Control-Allow-Headers', 'content-type,x-atlas-api-key');

    $response = $this->withHeaders([
        'Origin' => $origin,
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions', [
        'type' => 'like',
        'url' => 'https://cdn.example.test/media/cors-check.jpg',
    ]);

    $response->assertSuccessful();
    $response->assertHeader('Access-Control-Allow-Origin', $origin);
    $response->assertJsonPath('reaction.type', 'like');
});

test('extension reactions endpoint creates file applies reaction and queues download', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionReactionApiKey('valid-api-key', $user->id);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions', [
        'type' => 'like',
        'url' => 'https://cdn.example.test/media/new-file.jpg',
        'referrer_url' => 'https://www.example.test/post/123',
        'referrer_url_hash_aware' => 'https://www.example.test/post/123#media-id-42',
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('reaction.type', 'like');
    $response->assertJsonPath('download.requested', true);
    $response->assertJsonPath('download.downloaded_at', null);
    $response->assertJsonPath('file.url', 'https://cdn.example.test/media/new-file.jpg');
    $response->assertJsonPath('file.referrer_url', 'https://www.example.test/post/123#media-id-42');
    $response->assertJsonPath('file.preview_url', 'https://cdn.example.test/media/new-file.jpg');

    $file = File::query()->where('url', 'https://cdn.example.test/media/new-file.jpg')->first();
    expect($file)->not->toBeNull();
    expect($file?->source)->toBe('example.test');
    expect($file?->referrer_url)->toBe('https://www.example.test/post/123#media-id-42');
    expect($file?->preview_url)->toBe('https://cdn.example.test/media/new-file.jpg');
    expect(data_get($file?->listing_metadata, 'extension_user_id'))->toBe($user->id);

    $reaction = Reaction::query()
        ->where('user_id', $user->id)
        ->where('file_id', $file?->id)
        ->first();

    expect($reaction)->not->toBeNull();
    expect($reaction?->type)->toBe('like');

    Queue::assertPushed(DownloadFile::class, function (DownloadFile $job) use ($file): bool {
        return $job->fileId === $file?->id;
    });
});

test('extension reactions can update a downloaded file without queueing another download', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionReactionApiKey('valid-api-key', $user->id);

    $downloadedAt = now()->subMinute();
    $file = File::factory()->create([
        'source' => 'example.test',
        'url' => 'https://cdn.example.test/media/already-downloaded.jpg',
        'referrer_url' => 'https://www.example.test/post/123',
        'preview_url' => 'https://cdn.example.test/media/already-downloaded.jpg',
        'downloaded' => true,
        'downloaded_at' => $downloadedAt,
        'path' => 'downloads/already-downloaded.jpg',
        'filename' => 'already-downloaded',
        'ext' => 'jpg',
    ]);

    Reaction::query()->create([
        'file_id' => $file->id,
        'user_id' => $user->id,
        'type' => 'like',
    ]);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions', [
        'type' => 'love',
        'download_behavior' => 'skip',
        'url' => 'https://cdn.example.test/media/already-downloaded.jpg',
        'referrer_url_hash_aware' => 'https://www.example.test/post/123#media-id-42',
        'page_url' => 'https://www.example.test/post/123',
        'tag_name' => 'img',
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('reaction.type', 'love');
    $response->assertJsonPath('download.requested', false);
    $response->assertJsonPath('download.downloaded_at', $downloadedAt->toIso8601String());

    expect(Reaction::query()
        ->where('user_id', $user->id)
        ->where('file_id', $file->id)
        ->value('type'))->toBe('love');

    Queue::assertNotPushed(DownloadFile::class);
});

test('extension reactions can force a fresh download for an already-downloaded file', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionReactionApiKey('valid-api-key', $user->id);

    $downloadedAt = now()->subMinute();
    $file = File::factory()->create([
        'source' => 'example.test',
        'url' => 'https://cdn.example.test/media/force-redownload.jpg',
        'referrer_url' => 'https://www.example.test/post/456',
        'preview_url' => 'https://cdn.example.test/media/force-redownload.jpg',
        'downloaded' => true,
        'downloaded_at' => $downloadedAt,
        'path' => 'downloads/force-redownload.jpg',
        'filename' => 'force-redownload',
        'ext' => 'jpg',
    ]);

    Reaction::query()->create([
        'file_id' => $file->id,
        'user_id' => $user->id,
        'type' => 'like',
    ]);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions', [
        'type' => 'like',
        'download_behavior' => 'force',
        'url' => 'https://cdn.example.test/media/force-redownload.jpg',
        'referrer_url_hash_aware' => 'https://www.example.test/post/456#media-id-42',
        'page_url' => 'https://www.example.test/post/456',
        'tag_name' => 'img',
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('reaction.type', 'like');
    $response->assertJsonPath('download.requested', true);
    $response->assertJsonPath('download.downloaded_at', $downloadedAt->toIso8601String());

    Queue::assertPushed(DownloadFile::class, function (DownloadFile $job) use ($file): bool {
        return $job->fileId === $file->id && $job->forceDownload === true;
    });
});

test('extension reactions endpoint rejects non-http urls', function () {
    $user = User::factory()->create();
    setExtensionReactionApiKey('valid-api-key', $user->id);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions', [
        'type' => 'like',
        'url' => 'blob:https://x.com/00c34be5-77d2-49d2-b520-30369de3b587',
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('message', 'A valid media URL is required.');
});

test('extension video reactions on x mark files for yt-dlp', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionReactionApiKey('valid-api-key', $user->id);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions', [
        'type' => 'love',
        'url' => 'https://x.com/Ronycoder/status/2027740182682996825',
        'referrer_url_hash_aware' => 'https://x.com/Ronycoder/status/2027740182682996825',
        'page_url' => 'https://x.com/Ronycoder/status/2027740182682996825',
        'tag_name' => 'video',
    ]);

    $response->assertSuccessful();

    $file = File::query()->where('url', 'https://x.com/Ronycoder/status/2027740182682996825')->first();
    expect($file)->not->toBeNull();
    expect(data_get($file?->listing_metadata, 'tag_name'))->toBe('video');
    expect(data_get($file?->listing_metadata, 'page_url'))->toBe('https://x.com/Ronycoder/status/2027740182682996825');
    expect(data_get($file?->listing_metadata, 'download_via'))->toBe('yt-dlp');
});

test('extension video reactions prefer page url when yt-dlp is selected', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionReactionApiKey('valid-api-key', $user->id);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions', [
        'type' => 'like',
        'url' => 'https://video.xx.fbcdn.net/v/t42.1790-2/10000000_n.jpg',
        'referrer_url_hash_aware' => 'https://www.facebook.com/reel/735842842730500',
        'page_url' => 'https://www.facebook.com/reel/735842842730500',
        'tag_name' => 'video',
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('file.url', 'https://www.facebook.com/reel/735842842730500');

    $file = File::query()->where('url', 'https://www.facebook.com/reel/735842842730500')->first();
    expect($file)->not->toBeNull();
    expect($file?->source)->toBe('facebook.com');
    expect(data_get($file?->listing_metadata, 'download_via'))->toBe('yt-dlp');
    expect(data_get($file?->listing_metadata, 'tag_name'))->toBe('video');
});

test('extension reactions update legacy extension source rows to the referrer domain', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionReactionApiKey('valid-api-key', $user->id);

    $legacyFile = File::query()->create([
        'source' => 'extension',
        'url' => 'https://cdn.example.test/media/legacy-file.jpg',
        'referrer_url' => 'https://old.example.test/post/1',
        'preview_url' => 'https://cdn.example.test/media/legacy-file.jpg',
        'listing_metadata' => [],
        'filename' => 'legacy-file',
        'ext' => 'jpg',
    ]);

    $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions', [
        'type' => 'like',
        'url' => 'https://cdn.example.test/media/legacy-file.jpg',
        'referrer_url_hash_aware' => 'https://www.example.test/post/123#image-1',
        'page_url' => 'https://www.example.test/post/123',
        'tag_name' => 'img',
    ])->assertSuccessful();

    $legacyFile->refresh();

    expect($legacyFile->source)->toBe('example.test');
    expect($legacyFile->referrer_url)->toBe('https://www.example.test/post/123#image-1');
});

test('extension reactions payload forwards cookies and user agent to queued download job', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionReactionApiKey('valid-api-key', $user->id);

    $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
        'User-Agent' => 'AtlasExtensionHeaderUA/9.0',
    ])->postJson('/api/extension/reactions', [
        'type' => 'like',
        'url' => 'https://cdn.example.test/media/runtime-options.jpg',
        'referrer_url_hash_aware' => 'https://www.example.test/post/999',
        'cookies' => [[
            'name' => 'auth_token',
            'value' => 'secret123',
            'domain' => '.cdn.example.test',
            'path' => '/',
            'secure' => true,
            'http_only' => true,
            'host_only' => false,
            'expires_at' => null,
        ]],
        'user_agent' => 'AtlasExtensionBodyUA/1.2.3',
    ])->assertSuccessful();

    Queue::assertPushed(DownloadFile::class, function (DownloadFile $job): bool {
        return $job->runtimeContext === [
            'cookies' => [[
                'name' => 'auth_token',
                'value' => 'secret123',
                'domain' => 'cdn.example.test',
                'path' => '/',
                'secure' => true,
                'http_only' => true,
                'host_only' => false,
                'expires_at' => null,
            ]],
            'user_agent' => 'AtlasExtensionBodyUA/1.2.3',
        ];
    });
});

test('single deviantart downloads create a derived user container', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionReactionApiKey('valid-api-key', $user->id);

    $artUrl = 'https://www.deviantart.com/deviantartbender/art/Visionaries-Leoric-Star-Comics-1307302462';

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions', [
        'type' => 'like',
        'url' => 'https://images.example.test/single-da-image.jpg',
        'referrer_url_hash_aware' => $artUrl,
        'page_url' => $artUrl,
        'tag_name' => 'img',
    ]);

    $response->assertSuccessful();

    $file = File::query()->where('url', 'https://images.example.test/single-da-image.jpg')->first();

    expect($file)->not->toBeNull();
    expect(data_get($file?->listing_metadata, 'post_container_referrer_url'))->toBeNull();
    expect(data_get($file?->listing_metadata, 'user_container_source'))->toBe('deviantart.com');
    expect(data_get($file?->listing_metadata, 'user_container_source_id'))->toBe('deviantartbender');
    expect(data_get($file?->listing_metadata, 'user_container_referrer_url'))
        ->toBe('https://www.deviantart.com/deviantartbender/gallery');

    $this->assertDatabaseHas('containers', [
        'type' => 'User',
        'source' => 'deviantart.com',
        'source_id' => 'deviantartbender',
        'referrer' => 'https://www.deviantart.com/deviantartbender/gallery',
    ]);

    $this->assertDatabaseMissing('containers', [
        'type' => 'Post',
        'source' => 'deviantart.com',
        'source_id' => $artUrl,
    ]);
});
