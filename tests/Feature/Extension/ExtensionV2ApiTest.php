<?php

use App\Enums\DownloadTransferStatus;
use App\Jobs\DownloadFile;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use App\Services\Downloads\DownloadTransferPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/ExtensionReactionsTestSupport.php';

uses(RefreshDatabase::class);

test('v2 ping requires a valid extension api key', function () {
    setExtensionReactionApiKey('valid-api-key');

    $this->getJson('/api/extension/v2/ping')->assertUnauthorized();
});

test('v2 ping returns reverb metadata for the private extension channel', function () {
    $user = User::factory()->create();
    setExtensionReactionApiKey('valid-api-key', $user->id);
    config()->set('broadcasting.connections.reverb.key', 'test-key');

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->getJson('/api/extension/v2/ping');

    $response->assertOk();
    $response->assertJsonPath('ok', true);
    $response->assertJsonPath('reverb.channel', 'private-extension-downloads.'.hash('sha256', 'valid-api-key'));
});

test('v2 reactions store file reaction metadata and queue positive downloads', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionReactionApiKey('valid-api-key', $user->id);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/v2/reactions', [
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

test('v2 reactions do not queue downloads for blacklist reactions', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionReactionApiKey('valid-api-key', $user->id);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/v2/reactions', [
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

test('v2 asset status checks match by asset url including downloaded video previews', function () {
    $user = User::factory()->create();
    setExtensionReactionApiKey('valid-api-key', $user->id);

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
        'preview_url' => 'https://video-cdn.example.test/stream/video.mp4',
        'referrer_url' => 'https://www.example.test/watch/video',
        'url' => 'https://www.example.test/watch/video',
    ]);
    Reaction::query()->create([
        'file_id' => $video->id,
        'type' => 'like',
        'user_id' => $user->id,
    ]);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/v2/assets/status', [
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

test('v2 asset status checks match skipped assets by referrer url', function () {
    $user = User::factory()->create();
    setExtensionReactionApiKey('valid-api-key', $user->id);

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
    ])->postJson('/api/extension/v2/assets/status', [
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

test('v2 asset status prefers active transfer progress over stale downloaded state', function () {
    $user = User::factory()->create();
    setExtensionReactionApiKey('valid-api-key', $user->id);

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
    ])->postJson('/api/extension/v2/assets/status', [
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

test('v2 file delete removes downloaded files with extension authentication', function () {
    Storage::fake(config('downloads.disk'));

    $user = User::factory()->create();
    setExtensionReactionApiKey('valid-api-key', $user->id);
    $file = File::factory()->create([
        'downloaded' => true,
        'downloaded_at' => now(),
        'path' => 'downloads/v2-delete.jpg',
        'preview_path' => 'downloads/preview/v2-delete.jpg',
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
        ->deleteJson("/api/extension/v2/files/{$file->id}", [
            'also_delete_record' => true,
            'also_from_disk' => true,
        ]);

    $response->assertOk();
    $response->assertJsonPath('deleted', true);
    $response->assertJsonPath('file_id', $file->id);

    expect(File::query()->whereKey($file->id)->exists())->toBeFalse()
        ->and(Reaction::query()->where('file_id', $file->id)->exists())->toBeFalse();

    Storage::disk(config('downloads.disk'))->assertMissing('downloads/v2-delete.jpg');
    Storage::disk(config('downloads.disk'))->assertMissing('downloads/preview/v2-delete.jpg');
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
