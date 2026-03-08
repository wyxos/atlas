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
    expect($file?->source)->toBe('extension');
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
    expect(data_get($file?->listing_metadata, 'download_via'))->toBe('yt-dlp');
    expect(data_get($file?->listing_metadata, 'tag_name'))->toBe('video');
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
