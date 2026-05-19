<?php

use App\Models\DeviantArtToken;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use App\Services\DeviantArtImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/BrowseIndexTestSupport.php';

uses(RefreshDatabase::class);

test('authenticated user can browse DeviantArt images through the official API service', function () {
    config([
        'services.deviantart.user_agent' => 'AtlasTest/1.0',
    ]);

    $user = User::factory()->create();
    DeviantArtToken::query()->create([
        'user_id' => $user->id,
        'access_token' => 'connected-access-token',
        'refresh_token' => 'connected-refresh-token',
        'scope' => 'browse user',
        'expires_at' => now()->addHour(),
    ]);

    Http::fake([
        'https://www.deviantart.com/api/v1/oauth2/browse/home*' => Http::response([
            'has_more' => true,
            'next_offset' => 20,
            'estimated_total' => 42,
            'results' => [[
                'deviationid' => '52BAFA97-9DB9-0E5F-FF2D-C39083F89817',
                'url' => 'https://www.deviantart.com/artist/art/Astana-Hotel-487117484',
                'title' => 'Astana Hotel',
                'is_downloadable' => true,
                'is_mature' => false,
                'author' => [
                    'username' => 'artist',
                ],
                'published_time' => 1412745603,
                'preview' => [
                    'src' => 'https://th.example.test/preview.jpg',
                    'height' => 575,
                    'width' => 1024,
                ],
                'content' => [
                    'src' => 'https://fc.example.test/content.jpg',
                    'filesize' => 204800,
                    'height' => 1200,
                    'width' => 1600,
                ],
            ]],
        ]),
    ]);

    $response = $this->actingAs($user)->getJson('/api/browse?service=deviantart-images&q=mountain&limit=20');

    $response->assertSuccessful();
    $response->assertJsonCount(1, 'items');
    $response->assertJsonPath('nextPage', '20');
    $response->assertJsonPath('total', 42);
    $response->assertJsonPath('items.0.containers.0.type', 'User');
    $response->assertJsonPath('items.0.containers.0.source', 'deviantart.com');
    $response->assertJsonPath('items.0.containers.0.source_id', 'artist');
    $response->assertJsonPath('items.0.containers.0.referrer', 'https://www.deviantart.com/artist/gallery');
    $response->assertJsonPath('items.0.containers.0.browse_tab.params.service', 'deviantart-images');
    $response->assertJsonPath('items.0.containers.0.browse_tab.params.username', 'artist');

    $file = File::query()->with(['containers', 'metadata'])->sole();
    $payload = $file->metadata?->payload ?? [];

    expect($file->source)->toBe('deviantart.com')
        ->and($file->source_id)->toBe('52BAFA97-9DB9-0E5F-FF2D-C39083F89817')
        ->and($file->url)->toBe('https://fc.example.test/content.jpg')
        ->and($file->referrer_url)->toBe('https://www.deviantart.com/artist/art/Astana-Hotel-487117484')
        ->and($file->preview_url)->toBe('https://th.example.test/preview.jpg')
        ->and($file->title)->toBe('Astana Hotel')
        ->and($payload['download_mode'] ?? null)->toBe('content')
        ->and($file->containers)->toHaveCount(1)
        ->and($file->containers->first()?->type)->toBe('User')
        ->and($file->containers->first()?->source)->toBe('deviantart.com')
        ->and($file->containers->first()?->source_id)->toBe('artist');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_starts_with($request->url(), 'https://www.deviantart.com/api/v1/oauth2/browse/home')
        && $request->header('Authorization')[0] === 'Bearer connected-access-token'
        && $request->header('User-Agent')[0] === 'AtlasTest/1.0');

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://www.deviantart.com/oauth2/token');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/deviation/download/'));
});

test('DeviantArt browse stores listing media and does not resolve original downloads', function () {
    config([
        'services.deviantart.user_agent' => 'AtlasTest/1.0',
    ]);

    $user = User::factory()->create();
    DeviantArtToken::query()->create([
        'user_id' => $user->id,
        'access_token' => 'connected-access-token',
        'refresh_token' => 'connected-refresh-token',
        'scope' => 'browse user',
        'expires_at' => now()->addHour(),
    ]);

    Http::fake([
        'https://www.deviantart.com/api/v1/oauth2/browse/tags*' => Http::response([
            'has_more' => false,
            'results' => [[
                'deviationid' => '52BAFA97-9DB9-0E5F-FF2D-C39083F89817',
                'url' => 'https://www.deviantart.com/artist/art/Downloadable-487117484',
                'is_downloadable' => true,
                'content' => [
                    'src' => 'https://fc.example.test/content.jpg',
                    'height' => 900,
                    'width' => 1200,
                ],
            ]],
        ]),
        'https://www.deviantart.com/api/v1/oauth2/deviation/download/52BAFA97-9DB9-0E5F-FF2D-C39083F89817' => Http::response([
            'src' => 'https://download.example.test/original.png',
            'filename' => 'original.png',
            'width' => 2400,
            'height' => 1800,
            'filesize' => 8192,
        ]),
    ]);

    $response = $this->actingAs($user)->getJson('/api/browse?service=deviantart-images&tag=landscape&download=original&limit=1');

    $response->assertSuccessful();
    $response->assertJsonPath('items.0.containers.0.type', 'User');
    $response->assertJsonPath('items.0.containers.0.source', 'deviantart.com');
    $response->assertJsonPath('items.0.containers.0.source_id', 'artist');

    $file = File::query()->with(['containers', 'metadata'])->sole();
    $payload = $file->metadata?->payload ?? [];

    expect($file->url)->toBe('https://fc.example.test/content.jpg')
        ->and($file->ext)->toBe('jpg')
        ->and($file->mime_type)->toBe('image/jpeg')
        ->and($file->size)->toBeNull()
        ->and($payload['download_mode'] ?? null)->toBe('content')
        ->and($file->containers)->toHaveCount(1)
        ->and($file->containers->first()?->type)->toBe('User')
        ->and($file->containers->first()?->source)->toBe('deviantart.com')
        ->and($file->containers->first()?->source_id)->toBe('artist');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_starts_with($request->url(), 'https://www.deviantart.com/api/v1/oauth2/browse/tags')
        && $request->header('Authorization')[0] === 'Bearer connected-access-token'
        && $request->header('User-Agent')[0] === 'AtlasTest/1.0');

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://www.deviantart.com/oauth2/token');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/deviation/download/'));
});

test('DeviantArt browse suppresses already reacted deviations when listing media URL changes', function () {
    config([
        'services.deviantart.user_agent' => 'AtlasTest/1.0',
    ]);

    $user = User::factory()->create();
    DeviantArtToken::query()->create([
        'user_id' => $user->id,
        'access_token' => 'connected-access-token',
        'refresh_token' => 'connected-refresh-token',
        'scope' => 'browse user',
        'expires_at' => now()->addHour(),
    ]);

    $existing = File::factory()->create([
        'source' => DeviantArtImages::SOURCE,
        'source_id' => null,
        'url' => 'https://images.example.test/already-saved-original.jpg',
        'referrer_url' => 'https://www.deviantart.com/artist/art/Astana-Hotel-487117484',
        'preview_url' => 'https://images.example.test/already-saved-preview.jpg',
        'downloaded' => false,
        'downloaded_at' => null,
        'blacklisted_at' => null,
        'not_found' => false,
        'auto_blacklisted' => false,
    ]);
    Reaction::query()->create([
        'file_id' => $existing->id,
        'user_id' => $user->id,
        'type' => 'like',
    ]);

    Http::fake([
        'https://www.deviantart.com/api/v1/oauth2/browse/home*' => Http::response([
            'has_more' => false,
            'results' => [[
                'deviationid' => '52BAFA97-9DB9-0E5F-FF2D-C39083F89817',
                'url' => 'https://www.deviantart.com/artist/art/Astana-Hotel-487117484?utm_source=feed#comments',
                'title' => 'Astana Hotel',
                'author' => [
                    'username' => 'artist',
                ],
                'content' => [
                    'src' => 'https://fc.example.test/changed-listing-media.jpg',
                    'height' => 1200,
                    'width' => 1600,
                ],
            ]],
        ]),
    ]);

    $response = $this->actingAs($user)->getJson('/api/browse?service=deviantart-images&q=mountain&limit=20');

    $response->assertSuccessful();

    expect($response->json('items'))->toBe([])
        ->and(File::query()->count())->toBe(1)
        ->and(File::query()->where('url', 'https://fc.example.test/changed-listing-media.jpg')->exists())->toBeFalse();

    $existing->refresh();

    expect($existing->source_id)->toBe('52BAFA97-9DB9-0E5F-FF2D-C39083F89817')
        ->and($existing->url)->toBe('https://images.example.test/already-saved-original.jpg')
        ->and($existing->referrer_url)->toBe('https://www.deviantart.com/artist/art/Astana-Hotel-487117484');
});

test('DeviantArt browse suppresses already downloaded deviations by source id when media URL changes', function () {
    config([
        'services.deviantart.user_agent' => 'AtlasTest/1.0',
    ]);

    $user = User::factory()->create();
    DeviantArtToken::query()->create([
        'user_id' => $user->id,
        'access_token' => 'connected-access-token',
        'refresh_token' => 'connected-refresh-token',
        'scope' => 'browse user',
        'expires_at' => now()->addHour(),
    ]);

    $existing = File::factory()->create([
        'source' => DeviantArtImages::SOURCE,
        'source_id' => '52BAFA97-9DB9-0E5F-FF2D-C39083F89817',
        'url' => 'https://images.example.test/downloaded-original.jpg',
        'referrer_url' => 'https://www.deviantart.com/artist/art/Astana-Hotel-487117484',
        'preview_url' => 'https://images.example.test/downloaded-preview.jpg',
        'downloaded' => true,
        'downloaded_at' => now()->subHour(),
        'blacklisted_at' => null,
        'not_found' => false,
        'auto_blacklisted' => false,
    ]);

    Http::fake([
        'https://www.deviantart.com/api/v1/oauth2/browse/home*' => Http::response([
            'has_more' => false,
            'results' => [[
                'deviationid' => '52BAFA97-9DB9-0E5F-FF2D-C39083F89817',
                'url' => 'https://www.deviantart.com/artist/art/Astana-Hotel-487117484',
                'title' => 'Astana Hotel',
                'author' => [
                    'username' => 'artist',
                ],
                'content' => [
                    'src' => 'https://fc.example.test/downloaded-listing-media.jpg',
                    'height' => 1200,
                    'width' => 1600,
                ],
            ]],
        ]),
    ]);

    $response = $this->actingAs($user)->getJson('/api/browse?service=deviantart-images&q=mountain&limit=20');

    $response->assertSuccessful();

    expect($response->json('items'))->toBe([])
        ->and(File::query()->count())->toBe(1)
        ->and(File::query()->where('url', 'https://fc.example.test/downloaded-listing-media.jpg')->exists())->toBeFalse();

    $existing->refresh();

    expect($existing->url)->toBe('https://images.example.test/downloaded-original.jpg');
});

test('DeviantArt service requires a connected OAuth token', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(fn () => (new DeviantArtImages)->fetch(['limit' => 1]))
        ->toThrow(\RuntimeException::class, 'DeviantArt is not connected');
});
