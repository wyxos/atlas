<?php

use App\Enums\DownloadTransferStatus;
use App\Jobs\DownloadFile;
use App\Models\DownloadTransfer;
use App\Models\ExtensionAssetMatchIdentity;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use App\Services\Downloads\DownloadTransferPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/ExtensionApiTestSupport.php';

uses(RefreshDatabase::class);

test('extension ping requires a valid extension api key', function () {
    setExtensionApiKey('valid-api-key');

    $this->getJson('/api/extension/ping')->assertUnauthorized();
});

test('extension ping returns reverb metadata for the private extension channel', function () {
    $user = User::factory()->create();
    setExtensionApiKey('valid-api-key', $user->id);
    config()->set('broadcasting.connections.reverb.key', 'test-key');

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->getJson('/api/extension/ping');

    $response->assertOk();
    $response->assertJsonPath('ok', true);
    $response->assertJsonPath('reverb.channel', 'private-extension-downloads.'.hash('sha256', 'valid-api-key'));
});

test('extension reactions store file reaction metadata and queue positive downloads', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionApiKey('valid-api-key', $user->id);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions', [
        'asset_url' => 'https://cdn.example.test/media/new-file.jpg',
        'metadata' => [
            'asset_type' => 'image',
            'page_title' => 'Example post',
            'resolution' => '1280x720',
        ],
        'referrer_url' => 'https://www.example.test/post/123',
        'source' => 'example.test',
        'type' => 'like',
    ]);

    $response->assertCreated();
    $response->assertJsonPath('asset_url', 'https://cdn.example.test/media/new-file.jpg');
    $response->assertJsonPath('reaction.type', 'like');
    $response->assertJsonPath('download.requested', true);
    $response->assertJsonPath('file.url', 'https://cdn.example.test/media/new-file.jpg');
    $response->assertJsonPath('file.referrer_url', 'https://www.example.test/post/123');

    $file = File::query()->where('url', 'https://cdn.example.test/media/new-file.jpg')->first();

    expect($file)->not->toBeNull();
    expect($file?->source)->toBe('example.test');
    expect($file?->referrer_url)->toBe('https://www.example.test/post/123');
    expect($file?->preview_url)->toBe('https://cdn.example.test/media/new-file.jpg');
    expect(data_get($file?->listing_metadata, 'asset_type'))->toBe('image');
    expect(data_get($file?->listing_metadata, 'resolution'))->toBe('1280x720');
    expect(data_get($file?->listing_metadata, 'extension_user_id'))->toBe($user->id);

    expect(Reaction::query()
        ->where('file_id', $file?->id)
        ->where('user_id', $user->id)
        ->where('type', 'like')
        ->exists())->toBeTrue();

    Queue::assertPushed(DownloadFile::class, function (DownloadFile $job) use ($file): bool {
        return $job->fileId === $file?->id && $job->forceDownload === false;
    });
});

test('extension reactions do not queue downloads for blacklist reactions', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionApiKey('valid-api-key', $user->id);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions', [
        'asset_url' => 'https://cdn.example.test/media/blocked.jpg',
        'referrer_url' => 'https://www.example.test/post/blocked',
        'source' => 'example.test',
        'type' => 'blacklist',
    ]);

    $response->assertCreated();
    $response->assertJsonPath('reaction', null);
    $response->assertJsonPath('download.requested', false);

    Queue::assertNotPushed(DownloadFile::class);
});

test('extension asset status checks match by exact asset url', function () {
    $user = User::factory()->create();
    setExtensionApiKey('valid-api-key', $user->id);

    $image = File::factory()->create([
        'downloaded' => true,
        'downloaded_at' => now()->subMinute(),
        'preview_url' => 'https://cdn.example.test/media/existing-image.jpg',
        'url' => 'https://cdn.example.test/media/existing-image.jpg',
    ]);
    Reaction::query()->create([
        'file_id' => $image->id,
        'type' => 'love',
        'user_id' => $user->id,
    ]);

    $video = File::factory()->create([
        'downloaded' => true,
        'downloaded_at' => now()->subMinute(),
        'preview_url' => 'https://video-cdn.example.test/preview/video.mp4',
        'referrer_url' => 'https://www.example.test/watch/video',
        'url' => 'https://video-cdn.example.test/stream/video.mp4',
    ]);
    Reaction::query()->create([
        'file_id' => $video->id,
        'type' => 'like',
        'user_id' => $user->id,
    ]);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/assets/status', [
        'asset_urls' => [
            'https://cdn.example.test/media/existing-image.jpg',
            'https://video-cdn.example.test/stream/video.mp4',
            'https://cdn.example.test/media/missing.jpg',
        ],
    ]);

    $response->assertOk();

    $assets = $response->json('assets');

    expect($assets['https://cdn.example.test/media/existing-image.jpg']['file']['id'])->toBe($image->id);
    expect($assets['https://cdn.example.test/media/existing-image.jpg']['file']['atlas_url'])->toBe(url("/browse/file/{$image->id}"));
    expect($assets['https://cdn.example.test/media/existing-image.jpg']['reaction']['type'])->toBe('love');
    expect($assets['https://video-cdn.example.test/stream/video.mp4']['file']['id'])->toBe($video->id);
    expect($assets['https://video-cdn.example.test/stream/video.mp4']['file']['atlas_url'])->toBe(url("/browse/file/{$video->id}"));
    expect($assets['https://video-cdn.example.test/stream/video.mp4']['reaction']['type'])->toBe('like');
    expect($assets['https://cdn.example.test/media/missing.jpg'])->toBeNull();
});

test('extension asset status checks ignore preview url matches', function () {
    $user = User::factory()->create();
    setExtensionApiKey('valid-api-key', $user->id);

    $file = File::factory()->create([
        'downloaded' => true,
        'downloaded_at' => now()->subMinute(),
        'preview_url' => 'https://cdn.example.test/media/preview-only.jpg',
        'url' => 'https://cdn.example.test/media/original-only.jpg',
    ]);
    Reaction::query()->create([
        'file_id' => $file->id,
        'type' => 'love',
        'user_id' => $user->id,
    ]);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/assets/status', [
        'asset_urls' => [
            'https://cdn.example.test/media/preview-only.jpg',
            'https://cdn.example.test/media/original-only.jpg',
        ],
    ]);

    $response->assertOk();

    $assets = $response->json('assets');

    expect($assets['https://cdn.example.test/media/preview-only.jpg'])->toBeNull();
    expect($assets['https://cdn.example.test/media/original-only.jpg']['file']['id'])->toBe($file->id);
    expect($assets['https://cdn.example.test/media/original-only.jpg']['reaction']['type'])->toBe('love');
});

test('extension asset status checks match skipped assets by referrer url', function () {
    $user = User::factory()->create();
    setExtensionApiKey('valid-api-key', $user->id);

    $sharedReferrerUrl = 'https://www.example.test/post/shared';
    $olderPositive = File::factory()->create([
        'referrer_url' => $sharedReferrerUrl,
        'url' => 'https://cdn.example.test/media/shared-old.jpg',
    ]);
    Reaction::query()->create([
        'file_id' => $olderPositive->id,
        'type' => 'like',
        'user_id' => $user->id,
    ]);
    $newerBlacklisted = File::factory()->create([
        'blacklisted_at' => now(),
        'referrer_url' => $sharedReferrerUrl,
        'url' => 'https://cdn.example.test/media/shared-blacklisted.jpg',
    ]);
    $latestPositive = File::factory()->create([
        'referrer_url' => $sharedReferrerUrl,
        'url' => 'https://cdn.example.test/media/shared-latest.jpg',
    ]);
    Reaction::query()->create([
        'file_id' => $latestPositive->id,
        'type' => 'love',
        'user_id' => $user->id,
    ]);

    $blacklistedReferrerUrl = 'https://www.example.test/post/blacklisted';
    $blacklisted = File::factory()->create([
        'blacklisted_at' => now(),
        'referrer_url' => $blacklistedReferrerUrl,
        'url' => 'https://cdn.example.test/media/blacklisted.jpg',
    ]);
    File::factory()->create([
        'referrer_url' => $blacklistedReferrerUrl,
        'url' => 'https://cdn.example.test/media/unreacted-with-blacklist.jpg',
    ]);

    $unreactedReferrerUrl = 'https://www.example.test/post/unreacted';
    File::factory()->create([
        'referrer_url' => $unreactedReferrerUrl,
        'url' => 'https://cdn.example.test/media/unreacted-only.jpg',
    ]);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/assets/status', [
        'referrer_urls' => [
            $sharedReferrerUrl,
            $blacklistedReferrerUrl,
            $unreactedReferrerUrl,
            'https://www.example.test/post/missing',
        ],
    ]);

    $response->assertOk();

    $referrers = $response->json('referrers');

    expect($referrers[$sharedReferrerUrl]['file']['id'])->toBe($latestPositive->id);
    expect($referrers[$sharedReferrerUrl]['reaction']['type'])->toBe('love');
    expect($referrers[$blacklistedReferrerUrl]['file']['id'])->toBe($blacklisted->id);
    expect($referrers[$blacklistedReferrerUrl]['blacklisted_at'])->not->toBeNull();
    expect($referrers[$unreactedReferrerUrl])->toBeNull();
    expect($referrers['https://www.example.test/post/missing'])->toBeNull();
    expect($response->json('assets'))->toBe([]);

    expect($newerBlacklisted->id)->not->toBe($latestPositive->id);
});

test('extension asset status prefers active transfer progress over stale downloaded state', function () {
    $user = User::factory()->create();
    setExtensionApiKey('valid-api-key', $user->id);

    $file = File::factory()->create([
        'downloaded' => true,
        'downloaded_at' => now()->subHour(),
        'preview_url' => 'https://cdn.example.test/media/restarted.jpg',
        'url' => 'https://cdn.example.test/media/restarted.jpg',
    ]);

    DownloadTransfer::query()->create([
        'bytes_downloaded' => 12,
        'bytes_total' => 100,
        'domain' => 'cdn.example.test',
        'file_id' => $file->id,
        'last_broadcast_percent' => 12,
        'status' => DownloadTransferStatus::DOWNLOADING,
        'url' => $file->url,
    ]);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/assets/status', [
        'asset_urls' => [
            'https://cdn.example.test/media/restarted.jpg',
        ],
    ]);

    $response->assertOk();

    $asset = $response->json('assets')['https://cdn.example.test/media/restarted.jpg'];

    expect($asset['download']['status'])->toBe(DownloadTransferStatus::DOWNLOADING)
        ->and($asset['download']['progress_percent'])->toBe(12)
        ->and($asset['download']['downloaded_at'])->toBeNull();
});

test('extension asset status checks match by derived identity items', function () {
    $user = User::factory()->create();
    setExtensionApiKey('valid-api-key', $user->id);

    $file = File::factory()->create([
        'downloaded' => true,
        'downloaded_at' => now()->subMinute(),
        'preview_url' => 'https://scontent.example.test/v/t39/photo.jpg?oh=stored-token',
        'referrer_url' => 'https://www.facebook.com/photo/?fbid=122099716773370530&set=a.1',
        'url' => 'https://scontent.example.test/v/t39/photo.jpg?oh=stored-token',
    ]);
    ExtensionAssetMatchIdentity::query()->create([
        'file_id' => $file->id,
        'match_by' => 'referrer',
        'match_url' => 'https://www.facebook.com/photo/?fbid=122099716773370530',
        'rule_id' => 'facebook-photo-fbid',
        'rule_digest' => 'facebook-photo-fbid-v1',
    ]);
    Reaction::query()->create([
        'file_id' => $file->id,
        'type' => 'love',
        'user_id' => $user->id,
    ]);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/assets/status', [
        'match_items' => [[
            'lookup_id' => 'asset-1',
            'match_by' => 'referrer',
            'match_url' => 'https://www.facebook.com/photo/?fbid=122099716773370530',
            'rule_id' => 'facebook-photo-fbid',
            'rule_digest' => 'facebook-photo-fbid-v1',
        ]],
    ]);

    $response->assertOk();
    $response->assertJsonPath('matches.asset-1.file.id', $file->id);
    $response->assertJsonPath('matches.asset-1.reaction.type', 'love');
    $response->assertJsonPath('assets', []);
    $response->assertJsonPath('referrers', []);
});

test('extension reactions upsert match identity without rewriting raw urls', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionApiKey('valid-api-key', $user->id);

    $rawAssetUrl = 'https://scontent.example.test/v/t39/photo.jpg?oh=browser-token';
    $rawReferrerUrl = 'https://www.facebook.com/photo/?fbid=122099716773370530&set=a.1';
    $matchUrl = 'https://www.facebook.com/photo/?fbid=122099716773370530';

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions', [
        'asset_url' => $rawAssetUrl,
        'match_identity' => [
            'match_by' => 'referrer',
            'match_url' => $matchUrl,
            'rule_id' => 'facebook-photo-fbid',
            'rule_digest' => 'facebook-photo-fbid-v1',
        ],
        'referrer_url' => $rawReferrerUrl,
        'source' => 'facebook.com',
        'type' => 'love',
    ]);

    $response->assertCreated();

    $file = File::query()->where('url', $rawAssetUrl)->first();

    expect($file)->not->toBeNull();
    expect($file?->url)->toBe($rawAssetUrl);
    expect($file?->referrer_url)->toBe($rawReferrerUrl);
    expect(ExtensionAssetMatchIdentity::query()
        ->where('file_id', $file?->id)
        ->where('match_by', 'referrer')
        ->where('match_url', $matchUrl)
        ->where('rule_id', 'facebook-photo-fbid')
        ->where('rule_digest', 'facebook-photo-fbid-v1')
        ->exists())->toBeTrue();
});

test('extension file delete removes downloaded files with extension authentication', function () {
    Storage::fake(config('downloads.disk'));

    $user = User::factory()->create();
    setExtensionApiKey('valid-api-key', $user->id);
    $file = File::factory()->create([
        'downloaded' => true,
        'downloaded_at' => now(),
        'path' => 'downloads/extension-delete.jpg',
        'preview_path' => 'downloads/preview/extension-delete.jpg',
    ]);
    Reaction::query()->create([
        'file_id' => $file->id,
        'type' => 'funny',
        'user_id' => $user->id,
    ]);

    Storage::disk(config('downloads.disk'))->put($file->path, 'file');
    Storage::disk(config('downloads.disk'))->put($file->preview_path, 'preview');

    $response = $this
        ->withHeader('X-Atlas-Api-Key', 'valid-api-key')
        ->deleteJson("/api/extension/files/{$file->id}", [
            'also_delete_record' => true,
            'also_from_disk' => true,
        ]);

    $response->assertOk();
    $response->assertJsonPath('deleted', true);
    $response->assertJsonPath('file_id', $file->id);

    expect(File::query()->whereKey($file->id)->exists())->toBeFalse()
        ->and(Reaction::query()->where('file_id', $file->id)->exists())->toBeFalse();

    Storage::disk(config('downloads.disk'))->assertMissing('downloads/extension-delete.jpg');
    Storage::disk(config('downloads.disk'))->assertMissing('downloads/preview/extension-delete.jpg');
});

test('download transfer payloads include the browser asset url for extension progress matching', function () {
    $file = File::factory()->create([
        'preview_url' => 'https://video-cdn.example.test/stream/video.mp4',
        'url' => 'https://www.example.test/watch/video',
    ]);
    $transfer = DownloadTransfer::query()->create([
        'bytes_downloaded' => 0,
        'domain' => 'example.test',
        'file_id' => $file->id,
        'last_broadcast_percent' => 42,
        'status' => DownloadTransferStatus::DOWNLOADING,
        'url' => $file->url,
    ]);

    $payload = DownloadTransferPayload::forProgress($transfer, 42);

    expect($payload['asset_url'])->toBe('https://video-cdn.example.test/stream/video.mp4');
    expect($payload['file'])->toMatchArray([
        'atlas_url' => url("/browse/file/{$file->id}"),
        'id' => $file->id,
    ]);
});
