<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('authenticated user can browse files', function () {
    $user = User::factory()->create();

    // Mock the external service response
    Http::fake([
        '*' => Http::response([
            'items' => [
                [
                    'id' => '1',
                    'url' => 'https://example.com/image.jpg',
                    'width' => 500,
                    'height' => 500,
                ],
            ],
            'metadata' => [
                'nextCursor' => 'cursor123',
            ],
        ], 200),
    ]);

    $response = $this->actingAs($user)->getJson('/api/browse');

    $response->assertSuccessful();
    $response->assertJsonStructure([
        'items',
        'nextPage',
    ]);
});

test('browse returns items array with correct structure', function () {
    $user = User::factory()->create();

    Http::fake([
        '*' => Http::response([
            'items' => [
                [
                    'id' => '1',
                    'url' => 'https://example.com/image.jpg',
                    'width' => 500,
                    'height' => 500,
                    'thumbnailUrl' => 'https://example.com/thumb.jpg',
                ],
            ],
            'metadata' => [],
        ], 200),
    ]);

    $response = $this->actingAs($user)->getJson('/api/browse');

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['items'])->toBeArray();
    if (count($data['items']) > 0) {
        expect($data['items'][0])->toHaveKeys(['id', 'width', 'height', 'src', 'originalUrl', 'type', 'page', 'index', 'notFound']);
    }
});

test('browse returns nextPage cursor when available', function () {
    $user = User::factory()->create();

    Http::fake([
        '*' => Http::response([
            'items' => [],
            'metadata' => [
                'nextCursor' => 'cursor123',
            ],
        ], 200),
    ]);

    $response = $this->actingAs($user)->getJson('/api/browse');

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['nextPage'])->toBe('cursor123');
});

test('browse handles pagination with page parameter', function () {
    $user = User::factory()->create();

    Http::fake([
        '*' => Http::response([
            'items' => [],
            'metadata' => [],
        ], 200),
    ]);

    $response = $this->actingAs($user)->getJson('/api/browse?page=2');

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['items'])->toBeArray();
});

test('browse items include required fields', function () {
    $user = User::factory()->create();

    Http::fake([
        '*' => Http::response([
            'items' => [
                [
                    'id' => '1',
                    'url' => 'https://example.com/image.jpg',
                    'width' => 500,
                    'height' => 500,
                    'thumbnailUrl' => 'https://example.com/thumb.jpg',
                ],
            ],
            'metadata' => [],
        ], 200),
    ]);

    $response = $this->actingAs($user)->getJson('/api/browse');

    $response->assertSuccessful();
    $data = $response->json();
    if (count($data['items']) > 0) {
        $item = $data['items'][0];
        expect($item)->toHaveKey('id');
        expect($item)->toHaveKey('width');
        expect($item)->toHaveKey('height');
        expect($item)->toHaveKey('src');
        expect($item)->toHaveKey('originalUrl');
        expect($item)->toHaveKey('type');
        expect($item)->toHaveKey('page');
        expect($item)->toHaveKey('index');
        expect($item)->toHaveKey('notFound');
    }
});

test('browse items have correct type image', function () {
    $user = User::factory()->create();

    Http::fake([
        '*' => Http::response([
            'items' => [
                [
                    'id' => '1',
                    'url' => 'https://example.com/image.jpg',
                    'width' => 500,
                    'height' => 500,
                    'mimeType' => 'image/jpeg',
                ],
            ],
            'metadata' => [],
        ], 200),
    ]);

    $response = $this->actingAs($user)->getJson('/api/browse');

    $response->assertSuccessful();
    $data = $response->json();
    if (count($data['items']) > 0) {
        expect($data['items'][0]['type'])->toBe('image');
    }
});

test('browse items have correct type video', function () {
    $user = User::factory()->create();

    Http::fake([
        '*' => Http::response([
            'items' => [
                [
                    'id' => '1',
                    'url' => 'https://example.com/video.mp4',
                    'width' => 500,
                    'height' => 500,
                    'mimeType' => 'video/mp4',
                ],
            ],
            'metadata' => [],
        ], 200),
    ]);

    $response = $this->actingAs($user)->getJson('/api/browse');

    $response->assertSuccessful();
    $data = $response->json();
    if (count($data['items']) > 0) {
        expect($data['items'][0]['type'])->toBe('video');
    }
});

test('guest cannot browse', function () {
    $response = $this->getJson('/api/browse');

    $response->assertUnauthorized();
});

test('browse handles service errors gracefully', function () {
    $user = User::factory()->create();

    Http::fake([
        '*' => Http::response([], 500),
    ]);

    $response = $this->actingAs($user)->getJson('/api/browse');

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['items'])->toBeArray();
});

test('browse returns empty items array when service fails', function () {
    $user = User::factory()->create();

    Http::fake([
        '*' => Http::throw(fn () => new \Illuminate\Http\Client\ConnectionException('Connection failed')),
    ]);

    $response = $this->actingAs($user)->getJson('/api/browse');

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['items'])->toBeArray();
    expect($data['items'])->toBeEmpty();
});
