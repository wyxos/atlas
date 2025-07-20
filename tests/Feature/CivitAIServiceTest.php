<?php

use App\Services\CivitAIService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

it('fetches and transforms images correctly from CivitAI API', function () {
    Http::fake([
        'civitai.com/api/v1/images*' => Http::response([
            'items' => [
                [
                    'id' => 12345,
                    'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/sample-image.jpeg',
                    'width' => 512,
                    'height' => 768,
                ]
            ],
            'metadata' => [
                'nextCursor' => 'next_cursor_token',
                'totalItems' => 1000
            ]
        ], 200)
    ]);

    $request = new Request(['page' => 1]);
    $service = new CivitAIService($request);
    $result = $service->fetch();

    expect($result['currentPage'])->toBe(1);
    expect($result['hasNextPage'])->toBeTrue();
    expect($result['nextCursor'])->toBe('next_cursor_token');
    expect($result['initialImages'])->toHaveCount(1);

    $image = $result['initialImages'][0];
    expect($image['id'])->toBe('civitai-image-12345');
    expect($image['src'])->toBe('https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/sample-image.jpeg');
    expect($image['width'])->toBe(512);
    expect($image['height'])->toBe(768);
    expect($image['page'])->toBe('page_1');
    expect($image['index'])->toBe(0);

    // Verify that the CivitAI API was called correctly
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'civitai.com/api/v1/images') &&
               $request->data()['page'] === 1 &&
               $request->data()['limit'] === 20 &&
               $request->data()['sort'] === 'Newest' &&
               $request->data()['period'] === 'AllTime' &&
               $request->data()['nsfw'] === 'false';
    });
});

it('handles cursor-based pagination correctly', function () {
    Http::fake([
        'civitai.com/api/v1/images*' => Http::response([
            'items' => [
                [
                    'id' => 99999,
                    'url' => 'https://image.civitai.com/cursor/paginated-image.jpeg',
                    'width' => 512,
                    'height' => 512,
                ]
            ],
            'metadata' => [
                'nextCursor' => 'new_cursor_token'
            ]
        ], 200)
    ]);

    $request = new Request(['cursor' => 'existing_cursor_token']);
    $service = new CivitAIService($request);
    $result = $service->fetch();

    expect($result['nextCursor'])->toBe('new_cursor_token');
    expect($result['initialImages'][0]['page'])->toBe('cursor_existing_cursor_token');

    // Verify that the CivitAI API was called with cursor instead of page
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'civitai.com/api/v1/images') &&
               $request->data()['cursor'] === 'existing_cursor_token' &&
               !isset($request->data()['page']); // Page should not be set when cursor is used
    });
});

it('transforms multiple images correctly', function () {
    Http::fake([
        'civitai.com/api/v1/images*' => Http::response([
            'items' => [
                [
                    'id' => 123456,
                    'url' => 'https://image.civitai.com/first-image.jpeg',
                    'width' => 1024,
                    'height' => 1536,
                ],
                [
                    'id' => 654321,
                    'url' => 'https://image.civitai.com/second-image.png',
                    'width' => 768,
                    'height' => 768,
                ]
            ],
            'metadata' => [
                'nextCursor' => 'cursor_for_next_batch'
            ]
        ], 200)
    ]);

    $request = new Request(['page' => 1]);
    $service = new CivitAIService($request);
    $result = $service->fetch();

    expect($result['initialImages'])->toHaveCount(2);

    $firstImage = $result['initialImages'][0];
    expect($firstImage)->toMatchArray([
        'id' => 'civitai-image-123456',
        'src' => 'https://image.civitai.com/first-image.jpeg',
        'width' => 1024,
        'height' => 1536,
        'page' => 'page_1',
        'index' => 0,
    ]);

    $secondImage = $result['initialImages'][1];
    expect($secondImage)->toMatchArray([
        'id' => 'civitai-image-654321',
        'src' => 'https://image.civitai.com/second-image.png',
        'width' => 768,
        'height' => 768,
        'page' => 'page_1',
        'index' => 1,
    ]);
});

it('handles API errors gracefully', function () {
    Http::fake([
        'civitai.com/api/v1/images*' => Http::response([], 500)
    ]);

    $request = new Request(['page' => 1]);
    $service = new CivitAIService($request);

    expect(fn() => $service->fetch())->toThrow(Exception::class, 'CivitAI API request failed: 500');
});

it('handles empty API response', function () {
    Http::fake([
        'civitai.com/api/v1/images*' => Http::response([
            'items' => [],
            'metadata' => []
        ], 200)
    ]);

    $request = new Request(['page' => 1]);
    $service = new CivitAIService($request);
    $result = $service->fetch();

    expect($result['initialImages'])->toBeEmpty();
    expect($result['hasNextPage'])->toBeFalse();
    expect($result['nextCursor'])->toBeNull();
});

it('validates page parameter correctly', function () {
    Http::fake([
        'civitai.com/api/v1/images*' => Http::response([
            'items' => [],
            'metadata' => []
        ], 200)
    ]);

    // Test with string page parameter (should be cast to int)
    $request = new Request(['page' => '5']);
    $service = new CivitAIService($request);
    $result = $service->fetch();

    expect($result['currentPage'])->toBe(5);

    // Verify API was called with correct page
    Http::assertSent(function ($request) {
        return $request->data()['page'] === 5;
    });
});

it('uses correct API parameters', function () {
    Http::fake([
        'civitai.com/api/v1/images*' => Http::response([
            'items' => [],
            'metadata' => []
        ], 200)
    ]);

    $request = new Request(['page' => 1]);
    $service = new CivitAIService($request);
    $service->fetch();

    // Verify all required parameters are sent to CivitAI API
    Http::assertSent(function ($request) {
        $data = $request->data();
        return $data['limit'] === 20 &&
               $data['sort'] === 'Newest' &&
               $data['period'] === 'AllTime' &&
               $data['nsfw'] === 'false';
    });
});

