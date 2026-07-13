<?php

use App\Jobs\SyncLibraryFiles;
use App\Models\DeviantArtToken;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::clear();
});

function sourceMediaSignedUrl(string $path, DateTimeInterface $expiresAt): string
{
    $encode = static fn (array $value): string => rtrim(strtr(
        base64_encode(json_encode($value, JSON_THROW_ON_ERROR)),
        '+/',
        '-_',
    ), '=');
    $token = implode('.', [
        $encode(['alg' => 'none']),
        $encode(['exp' => $expiresAt->getTimestamp()]),
        'signature',
    ]);

    return "https://images.example.test/{$path}?token={$token}";
}

test('user can refresh DeviantArt source media for an existing file', function () {
    Bus::fake([SyncLibraryFiles::class]);

    $user = User::factory()->create();
    DeviantArtToken::query()->create([
        'user_id' => $user->id,
        'access_token' => 'connected-access-token',
        'refresh_token' => 'connected-refresh-token',
        'scope' => 'browse user',
        'expires_at' => now()->addHour(),
    ]);

    $deviationId = 'C2DC7BF1-82D3-4778-4BE4-23D1DA198CE0';

    Http::fake([
        "https://www.deviantart.com/api/v1/oauth2/deviation/{$deviationId}" => Http::response([
            'deviationid' => $deviationId,
            'url' => 'https://www.deviantart.com/exampleartist/art/Example-1329880490',
            'title' => 'Example',
            'is_downloadable' => false,
            'is_mature' => true,
            'premium_folder_data' => [
                'has_access' => true,
                'type' => 'watchers',
            ],
            'author' => [
                'username' => 'ExampleArtist',
            ],
            'stats' => [
                'comments' => 1,
                'favourites' => 2,
            ],
            'preview' => [
                'src' => 'https://images.example.test/fresh-preview.jpg',
                'height' => 720,
                'width' => 1024,
            ],
            'content' => [
                'src' => 'https://images.example.test/fresh-original.png',
                'filesize' => 4096,
                'height' => 1440,
                'width' => 2048,
            ],
        ]),
    ]);

    $file = File::factory()->create([
        'source' => 'deviantart.com',
        'source_id' => $deviationId,
        'url' => 'https://images.example.test/blurred-original.jpg',
        'preview_url' => 'https://images.example.test/blurred-preview.jpg',
        'referrer_url' => 'https://www.deviantart.com/exampleartist/art/Example-1329880490',
        'listing_metadata' => [
            'deviationid' => $deviationId,
            'premium_folder_data' => [
                'has_access' => false,
            ],
        ],
        'ext' => 'jpg',
        'mime_type' => 'image/jpeg',
        'size' => 1024,
        'downloaded' => false,
        'path' => null,
        'preview_path' => null,
    ]);

    $response = $this->actingAs($user)->postJson("/api/files/{$file->id}/refresh-source-media");

    $response->assertSuccessful();
    $response->assertJsonPath('supported', true);
    $response->assertJsonPath('changed', true);
    $response->assertJsonPath('file.url', 'https://images.example.test/fresh-original.png');
    $response->assertJsonPath('file.preview_url', 'https://images.example.test/fresh-preview.jpg');
    $response->assertJsonPath('file.source_media_url', "/api/files/{$file->id}/source-media/original");
    $response->assertJsonPath('file.source_media_preview_url', "/api/files/{$file->id}/source-media/preview");
    $response->assertJsonPath('file.ext', 'png');
    $response->assertJsonPath('file.mime_type', 'image/png');
    $response->assertJsonPath('file.size', 4096);
    $response->assertJsonPath('file.capabilities.refresh_source_media', true);
    $response->assertJsonPath('file.capabilities.dynamic_source_media', true);

    $file->refresh();
    expect($file->url)->toBe('https://images.example.test/fresh-original.png')
        ->and($file->preview_url)->toBe('https://images.example.test/fresh-preview.jpg')
        ->and($file->listing_metadata['premium_folder_data']['has_access'])->toBeTrue()
        ->and($file->listing_metadata['user_container_source_id'])->toBe('exampleartist')
        ->and($file->metadata?->payload['download_mode'])->toBe('content')
        ->and($file->metadata?->payload['width'])->toBe(2048)
        ->and($file->metadata?->payload['height'])->toBe(1440);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === "https://www.deviantart.com/api/v1/oauth2/deviation/{$deviationId}"
        && $request->header('Authorization')[0] === 'Bearer connected-access-token');

    Bus::assertDispatched(SyncLibraryFiles::class);
});

test('unexpired dynamic source media redirects without provider resolution', function () {
    Http::fake();

    $user = User::factory()->create();
    $originalUrl = sourceMediaSignedUrl('current-original.jpg', now()->addMinutes(10));
    $previewUrl = sourceMediaSignedUrl('current-preview.jpg', now()->addMinutes(10));
    $file = File::factory()->create([
        'source' => 'deviantart.com',
        'source_id' => '7DE5BE70-E7CE-7802-B917-54E0AC3CC52C',
        'url' => $originalUrl,
        'preview_url' => $previewUrl,
        'downloaded' => false,
        'path' => null,
    ]);

    $response = $this->actingAs($user)
        ->get("/api/files/{$file->id}/source-media/original");

    $response->assertRedirect($originalUrl);
    $response->assertHeader('Cache-Control', 'no-store, private');
    Http::assertNothingSent();
});

test('expired dynamic source media resolves once and reuses the fresh result for concurrent variants', function () {
    Bus::fake([SyncLibraryFiles::class]);

    $user = User::factory()->create();
    DeviantArtToken::query()->create([
        'user_id' => $user->id,
        'access_token' => 'connected-access-token',
        'refresh_token' => 'connected-refresh-token',
        'scope' => 'browse user',
        'expires_at' => now()->addHour(),
    ]);

    $deviationId = '3A827F28-6709-4308-8274-C734725E05C5';
    $freshOriginalUrl = sourceMediaSignedUrl('fresh-original.jpg', now()->addMinutes(10));
    $freshPreviewUrl = sourceMediaSignedUrl('fresh-preview.jpg', now()->addMinutes(10));
    Http::fake([
        "https://www.deviantart.com/api/v1/oauth2/deviation/{$deviationId}" => Http::response([
            'deviationid' => $deviationId,
            'url' => 'https://www.deviantart.com/exampleartist/art/Example-123',
            'author' => ['username' => 'ExampleArtist'],
            'preview' => [
                'src' => $freshPreviewUrl,
                'height' => 720,
                'width' => 1024,
            ],
            'content' => [
                'src' => $freshOriginalUrl,
                'filesize' => 4096,
                'height' => 1440,
                'width' => 2048,
            ],
        ]),
    ]);

    $expiredOriginalUrl = sourceMediaSignedUrl('expired-original.jpg', now()->subMinute());
    $expiredPreviewUrl = sourceMediaSignedUrl('expired-preview.jpg', now()->subMinute());
    $file = File::factory()->create([
        'source' => 'deviantart.com',
        'source_id' => $deviationId,
        'url' => $expiredOriginalUrl,
        'preview_url' => $expiredPreviewUrl,
        'downloaded' => false,
        'path' => null,
    ]);

    $previewResponse = $this->actingAs($user)
        ->get("/api/files/{$file->id}/source-media/preview");
    $originalResponse = $this->actingAs($user)
        ->get("/api/files/{$file->id}/source-media/original?refresh=1&retry=1");

    $previewResponse->assertRedirect($freshPreviewUrl);
    $originalResponse->assertRedirect($freshOriginalUrl);
    Http::assertSentCount(1);
    Bus::assertNothingDispatched();

    $file->refresh();
    expect($file->url)->toBe($expiredOriginalUrl)
        ->and($file->preview_url)->toBe($expiredPreviewUrl);
});

test('forced dynamic source media resolution replaces an otherwise unexpired URL', function () {
    $user = User::factory()->create();
    DeviantArtToken::query()->create([
        'user_id' => $user->id,
        'access_token' => 'connected-access-token',
        'refresh_token' => 'connected-refresh-token',
        'scope' => 'browse user',
        'expires_at' => now()->addHour(),
    ]);

    $deviationId = '41E13F67-7173-4C1C-AC3C-9D61D94ACE96';
    $freshOriginalUrl = sourceMediaSignedUrl('forced-original.jpg', now()->addMinutes(10));
    Http::fake([
        "https://www.deviantart.com/api/v1/oauth2/deviation/{$deviationId}" => Http::response([
            'deviationid' => $deviationId,
            'url' => 'https://www.deviantart.com/exampleartist/art/Example-456',
            'author' => ['username' => 'ExampleArtist'],
            'content' => [
                'src' => $freshOriginalUrl,
                'filesize' => 4096,
                'height' => 1440,
                'width' => 2048,
            ],
        ]),
    ]);

    $file = File::factory()->create([
        'source' => 'deviantart.com',
        'source_id' => $deviationId,
        'url' => sourceMediaSignedUrl('still-valid-original.jpg', now()->addMinutes(10)),
        'preview_url' => null,
        'downloaded' => false,
        'path' => null,
    ]);

    $response = $this->actingAs($user)
        ->get("/api/files/{$file->id}/source-media/original?refresh=1&retry=1");

    $response->assertRedirect($freshOriginalUrl);
    Http::assertSentCount(1);
});

test('refresh source media rejects unsupported sources', function () {
    Http::fake();

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'source_id' => null,
    ]);

    $response = $this->actingAs($user)->postJson("/api/files/{$file->id}/refresh-source-media");

    $response->assertUnprocessable();
    $response->assertJsonPath('supported', false);
    $response->assertJsonPath('changed', false);

    Http::assertNothingSent();
});
