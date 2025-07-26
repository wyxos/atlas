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

    expect($result['page'])->toBe(1); // Current page value
    expect($result['hasNextPage'])->toBeTrue();
    expect($result['nextPage'])->toBe('next_cursor_token'); // Next page value
    expect($result['items'])->toHaveCount(1);

    $item = $result['items'][0];
    expect($item['src'])->toBe('https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/sample-image.jpeg');
    expect($item['width'])->toBe(512);
    expect($item['height'])->toBe(768);
    expect($item['page'])->toBe(1); // Page identifier
    expect($item['index'])->toBe(0);

    // Verify that the CivitAI API was called correctly
    Http::assertSent(function ($request) {
        $data = $request->data();
        return str_contains($request->url(), 'civitai.com/api/v1/images') &&
               $data['cursor'] === 1 && // Page parameter becomes cursor
               $data['limit'] === 20 && // Default limit from service
               $data['sort'] === 'Most Reactions' &&
               $data['period'] === 'AllTime';
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

    $request = new Request(['page' => 'existing_cursor_token']); // Use page parameter
    $service = new CivitAIService($request);
    $result = $service->fetch();

    expect($result['nextPage'])->toBe('new_cursor_token'); // Check nextPage instead of nextCursor
    expect($result['page'])->toBe('existing_cursor_token'); // Current page value

    // Verify cursor-based page attribute format
    $item = $result['items'][0];
    expect($item['page'])->toBe('existing_cursor_token'); // Page identifier

    // Verify that the CivitAI API was called with cursor parameter
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'civitai.com/api/v1/images') &&
               $request->data()['cursor'] === 'existing_cursor_token';
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

    expect($result['items'])->toHaveCount(2);

    $firstItem = $result['items'][0];
    // Note: The actual File ID from database, not CivitAI ID
    expect($firstItem['src'])->toBe('https://image.civitai.com/first-image.jpeg');
    expect($firstItem['width'])->toBe(1024);
    expect($firstItem['height'])->toBe(1536);
    expect($firstItem['page'])->toBe(1); // Page identifier
    expect($firstItem['index'])->toBe(0);

    $secondItem = $result['items'][1];
    expect($secondItem['src'])->toBe('https://image.civitai.com/second-image.png');
    expect($secondItem['width'])->toBe(768);
    expect($secondItem['height'])->toBe(768);
    expect($secondItem['page'])->toBe(1); // Page identifier
    expect($secondItem['index'])->toBe(1);
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

    expect($result['items'])->toBeEmpty();
    expect($result['hasNextPage'])->toBeFalse();
    expect($result['nextPage'])->toBeNull();
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

    expect($result['page'])->toBe('5'); // Page value as received

    // Verify API was called with correct cursor parameter
    Http::assertSent(function ($request) {
        return $request->data()['cursor'] === '5';
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
        return $data['limit'] === 20 && // Default limit from service
               $data['sort'] === 'Most Reactions' &&
               $data['period'] === 'AllTime' &&
               $data['cursor'] === 1; // Page parameter becomes cursor
    });
});

