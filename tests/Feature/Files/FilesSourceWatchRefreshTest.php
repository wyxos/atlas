<?php

use App\Jobs\SyncLibraryIndex;
use App\Models\Container;
use App\Models\DeviantArtToken;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('user can watch DeviantArt source account and refresh source media for a file', function () {
    Bus::fake([SyncLibraryIndex::class]);

    $user = User::factory()->create();
    DeviantArtToken::query()->create([
        'user_id' => $user->id,
        'access_token' => 'connected-access-token',
        'refresh_token' => 'connected-refresh-token',
        'scope' => 'basic browse user user.manage',
        'expires_at' => now()->addHour(),
    ]);

    $deviationId = 'C2DC7BF1-82D3-4778-4BE4-23D1DA198CE0';

    Http::fake([
        'https://www.deviantart.com/api/v1/oauth2/user/friends/watch/exampleartist' => Http::response([
            'success' => true,
        ]),
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
    $container = Container::factory()->create([
        'type' => 'User',
        'source' => 'deviantart.com',
        'source_id' => 'exampleartist',
        'referrer' => 'https://www.deviantart.com/exampleartist/gallery',
    ]);
    $file->containers()->attach($container);

    $response = $this->actingAs($user)->postJson("/api/files/{$file->id}/source-watch-refresh");

    $response->assertSuccessful();
    $response->assertJsonPath('supported', true);
    $response->assertJsonPath('watched', true);
    $response->assertJsonPath('changed', true);
    $response->assertJsonPath('file.url', 'https://images.example.test/fresh-original.png');
    $response->assertJsonPath('file.preview_url', 'https://images.example.test/fresh-preview.jpg');
    $response->assertJsonPath('file.capabilities.refresh_source_media', true);
    $response->assertJsonPath('file.capabilities.watch_source_and_refresh', true);

    $file->refresh();
    expect($file->url)->toBe('https://images.example.test/fresh-original.png')
        ->and($file->preview_url)->toBe('https://images.example.test/fresh-preview.jpg')
        ->and($file->listing_metadata['premium_folder_data']['has_access'])->toBeTrue()
        ->and($file->listing_metadata['user_container_source_id'])->toBe('exampleartist')
        ->and($file->metadata?->payload['download_mode'])->toBe('content');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://www.deviantart.com/api/v1/oauth2/user/friends/watch/exampleartist'
        && $request->header('Authorization')[0] === 'Bearer connected-access-token'
        && $request->data()['watch[friend]'] === '1'
        && $request->data()['watch[deviations]'] === '1');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === "https://www.deviantart.com/api/v1/oauth2/deviation/{$deviationId}"
        && $request->header('Authorization')[0] === 'Bearer connected-access-token');

    Bus::assertDispatched(SyncLibraryIndex::class);
});

test('source watch refresh rejects unsupported sources', function () {
    Http::fake();

    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'local',
        'source_id' => null,
    ]);

    $response = $this->actingAs($user)->postJson("/api/files/{$file->id}/source-watch-refresh");

    $response->assertUnprocessable();
    $response->assertJsonPath('supported', false);
    $response->assertJsonPath('watched', false);
    $response->assertJsonPath('changed', false);

    Http::assertNothingSent();
});
