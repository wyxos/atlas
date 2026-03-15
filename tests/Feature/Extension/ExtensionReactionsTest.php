<?php

use App\Jobs\DownloadFile;
use App\Models\Container;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function setExtensionReactionApiKey(string $value, ?int $userId = null): void
{
    DB::table('settings')->updateOrInsert([
        'key' => 'extension.api_key_hash',
        'machine' => '',
    ], [
        'value' => hash('sha256', $value),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    if ($userId !== null) {
        DB::table('settings')->updateOrInsert([
            'key' => 'extension.api_key_user_id',
            'machine' => '',
        ], [
            'value' => (string) $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

test('extension reactions endpoint requires a valid api key', function () {
    setExtensionReactionApiKey('valid-api-key');

    $response = $this->postJson('/api/extension/reactions', [
        'type' => 'like',
        'url' => 'https://cdn.example.test/media/new-file.jpg',
    ]);

    $response->assertUnauthorized();
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

test('single civitai image reactions persist civitai source and derived post user and resource containers', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionReactionApiKey('valid-api-key', $user->id);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions', [
        'type' => 'like',
        'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/cf304569-f64a-4f90-aa1b-16bfb641f143/original=true,quality=90/1341e338-9de2-4d1a-a6ff-6c86fb7ad759.jpeg',
        'referrer_url_hash_aware' => 'https://civitai.com/images/76490204',
        'page_url' => 'https://civitai.com/images/76490204',
        'tag_name' => 'img',
        'listing_metadata_overrides' => [
            'postId' => 23377656,
            'username' => 'shepretends',
            'resource_containers' => [
                [
                    'type' => 'Checkpoint',
                    'modelId' => 833294,
                    'modelVersionId' => 1190596,
                    'referrerUrl' => 'https://civitai.com/models/833294/noobai-xl-nai-xl?modelVersionId=1190596',
                ],
                [
                    'type' => 'LoRA',
                    'modelId' => 1368095,
                    'modelVersionId' => 1545615,
                    'referrerUrl' => 'https://civitai.com/models/1368095/incase-style-noobai?modelVersionId=1545615',
                ],
            ],
        ],
    ]);

    $response->assertSuccessful();

    $file = File::query()->where('url', 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/cf304569-f64a-4f90-aa1b-16bfb641f143/original=true,quality=90/1341e338-9de2-4d1a-a6ff-6c86fb7ad759.jpeg')->first();

    expect($file)->not->toBeNull();
    expect($file?->source)->toBe('CivitAI');
    expect($file?->referrer_url)->toBe('https://civitai.com/images/76490204');
    expect(data_get($file?->listing_metadata, 'postId'))->toBe(23377656);
    expect(data_get($file?->listing_metadata, 'username'))->toBe('shepretends');
    expect(data_get($file?->listing_metadata, 'resource_containers.0.modelVersionId'))->toBe(1190596);
    expect(data_get($file?->listing_metadata, 'resource_containers.1.modelVersionId'))->toBe(1545615);

    $this->assertDatabaseHas('containers', [
        'type' => 'Post',
        'source' => 'CivitAI',
        'source_id' => '23377656',
        'referrer' => 'https://civitai.com/posts/23377656',
    ]);
    $this->assertDatabaseHas('containers', [
        'type' => 'User',
        'source' => 'CivitAI',
        'source_id' => 'shepretends',
        'referrer' => 'https://civitai.com/user/shepretends',
    ]);
    $this->assertDatabaseHas('containers', [
        'type' => 'Checkpoint',
        'source' => 'CivitAI',
        'source_id' => '1190596',
        'referrer' => 'https://civitai.com/models/833294/noobai-xl-nai-xl?modelVersionId=1190596',
    ]);
    $this->assertDatabaseHas('containers', [
        'type' => 'LoRA',
        'source' => 'CivitAI',
        'source_id' => '1545615',
        'referrer' => 'https://civitai.com/models/1368095/incase-style-noobai?modelVersionId=1545615',
    ]);

    expect($file?->containers()->where('type', 'Post')->exists())->toBeTrue();
    expect($file?->containers()->where('type', 'User')->exists())->toBeTrue();
    expect($file?->containers()->where('type', 'Checkpoint')->exists())->toBeTrue();
    expect($file?->containers()->where('type', 'LoRA')->exists())->toBeTrue();
});

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
        'type' => 'Post',
        'source' => 'deviantart.com',
        'source_id' => 'https://www.deviantart.com/artist/art/post-1',
        'referrer' => 'https://www.deviantart.com/artist/art/post-1',
    ]);

    $container = Container::query()
        ->where('type', 'Post')
        ->where('source', 'deviantart.com')
        ->where('source_id', 'https://www.deviantart.com/artist/art/post-1')
        ->first();

    expect($container)->not->toBeNull();
    expect($firstFile?->containers()->where('containers.id', $container?->id)->exists())->toBeTrue();
    expect($secondFile?->containers()->where('containers.id', $container?->id)->exists())->toBeTrue();

    $this->assertDatabaseHas('containers', [
        'type' => 'User',
        'source' => 'deviantart.com',
        'source_id' => 'artist',
        'referrer' => 'https://www.deviantart.com/artist/gallery',
    ]);

    $userContainer = Container::query()
        ->where('type', 'User')
        ->where('source', 'deviantart.com')
        ->where('source_id', 'artist')
        ->first();

    expect($userContainer)->not->toBeNull();
    expect($firstFile?->containers()->where('containers.id', $userContainer?->id)->exists())->toBeTrue();
    expect($secondFile?->containers()->where('containers.id', $userContainer?->id)->exists())->toBeTrue();

    Queue::assertPushed(DownloadFile::class, 2);
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
        'type' => 'Post',
        'source' => 'deviantart.com',
        'source_id' => $galleryUrl,
        'referrer' => $galleryUrl,
    ]);

    $this->assertDatabaseHas('containers', [
        'type' => 'User',
        'source' => 'deviantart.com',
        'source_id' => 'aipayop',
        'referrer' => 'https://www.deviantart.com/aipayop/gallery',
    ]);
});

test('extension batch reactions persist civitai shared containers with per-item image referrers', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionReactionApiKey('valid-api-key', $user->id);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions/batch', [
        'type' => 'like',
        'primary_candidate_id' => 'image-76490204',
        'listing_metadata_overrides' => [
            'postId' => 16973563,
            'username' => 'DigitalPastel',
            'resource_containers' => [
                [
                    'type' => 'Checkpoint',
                    'modelId' => 621659,
                    'modelVersionId' => 1763717,
                    'referrerUrl' => 'https://civitai.com/models/621659/smooth-mix-old-ver-noobaiillustriouspony?modelVersionId=1763717',
                ],
                [
                    'type' => 'LoRA',
                    'modelId' => 1159892,
                    'modelVersionId' => 1304665,
                    'referrerUrl' => 'https://civitai.com/models/1159892/all-disney-princess-illustrious?modelVersionId=1304665',
                ],
            ],
        ],
        'items' => [
            [
                'candidate_id' => 'image-76477306',
                'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/0d6b721b-6256-4849-99ba-05c7cbb5b64f/transcode=true,original=true,quality=90/4MGM4VAVYH67B8TYY43NVH1780.mp4',
                'referrer_url_hash_aware' => 'https://civitai.com/images/76477306',
                'page_url' => 'https://civitai.com/posts/16973563',
                'tag_name' => 'video',
            ],
            [
                'candidate_id' => 'image-76490204',
                'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/cf304569-f64a-4f90-aa1b-16bfb641f143/original=true,quality=90/1341e338-9de2-4d1a-a6ff-6c86fb7ad759.jpeg',
                'referrer_url_hash_aware' => 'https://civitai.com/images/76490204',
                'page_url' => 'https://civitai.com/posts/16973563',
                'tag_name' => 'img',
            ],
        ],
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('file.url', 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/cf304569-f64a-4f90-aa1b-16bfb641f143/original=true,quality=90/1341e338-9de2-4d1a-a6ff-6c86fb7ad759.jpeg');
    $response->assertJsonPath('file.referrer_url', 'https://civitai.com/images/76490204');

    $videoFile = File::query()->where('url', 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/0d6b721b-6256-4849-99ba-05c7cbb5b64f/transcode=true,original=true,quality=90/4MGM4VAVYH67B8TYY43NVH1780.mp4')->first();
    $imageFile = File::query()->where('url', 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/cf304569-f64a-4f90-aa1b-16bfb641f143/original=true,quality=90/1341e338-9de2-4d1a-a6ff-6c86fb7ad759.jpeg')->first();

    expect($videoFile)->not->toBeNull();
    expect($imageFile)->not->toBeNull();
    expect($videoFile?->source)->toBe('CivitAI');
    expect($imageFile?->source)->toBe('CivitAI');
    expect($videoFile?->referrer_url)->toBe('https://civitai.com/images/76477306');
    expect($imageFile?->referrer_url)->toBe('https://civitai.com/images/76490204');
    expect(data_get($videoFile?->listing_metadata, 'postId'))->toBe(16973563);
    expect(data_get($imageFile?->listing_metadata, 'postId'))->toBe(16973563);
    expect(data_get($videoFile?->listing_metadata, 'resource_containers.0.modelVersionId'))->toBe(1763717);
    expect(data_get($imageFile?->listing_metadata, 'resource_containers.1.modelVersionId'))->toBe(1304665);

    $this->assertDatabaseHas('containers', [
        'type' => 'Post',
        'source' => 'CivitAI',
        'source_id' => '16973563',
        'referrer' => 'https://civitai.com/posts/16973563',
    ]);
    $this->assertDatabaseHas('containers', [
        'type' => 'User',
        'source' => 'CivitAI',
        'source_id' => 'DigitalPastel',
        'referrer' => 'https://civitai.com/user/DigitalPastel',
    ]);
    $this->assertDatabaseHas('containers', [
        'type' => 'Checkpoint',
        'source' => 'CivitAI',
        'source_id' => '1763717',
        'referrer' => 'https://civitai.com/models/621659/smooth-mix-old-ver-noobaiillustriouspony?modelVersionId=1763717',
    ]);
    $this->assertDatabaseHas('containers', [
        'type' => 'LoRA',
        'source' => 'CivitAI',
        'source_id' => '1304665',
        'referrer' => 'https://civitai.com/models/1159892/all-disney-princess-illustrious?modelVersionId=1304665',
    ]);

    expect($videoFile?->containers()->where('type', 'Post')->exists())->toBeTrue();
    expect($videoFile?->containers()->where('type', 'User')->exists())->toBeTrue();
    expect($videoFile?->containers()->where('type', 'Checkpoint')->exists())->toBeTrue();
    expect($videoFile?->containers()->where('type', 'LoRA')->exists())->toBeTrue();
    expect($imageFile?->containers()->where('type', 'Post')->exists())->toBeTrue();
    expect($imageFile?->containers()->where('type', 'User')->exists())->toBeTrue();
    expect($imageFile?->containers()->where('type', 'Checkpoint')->exists())->toBeTrue();
    expect($imageFile?->containers()->where('type', 'LoRA')->exists())->toBeTrue();
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
