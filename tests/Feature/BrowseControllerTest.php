<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Create and authenticate a user for each test
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('can access browse page', function () {
    // Mock the CivitAI API response
    Http::fake([
        'civitai.com/api/v1/images*' => Http::response([
            'items' => [
                [
                    'id' => 12345,
                    'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/sample-image.jpeg',
                    'width' => 512,
                    'height' => 768,
                    'hash' => 'sample_hash_123',
                    'meta' => [
                        'Model' => 'SDXL 1.0',
                        'prompt' => 'a beautiful landscape',
                        'seed' => 42
                    ]
                ]
            ],
            'metadata' => [
                'nextCursor' => 'next_cursor_token',
                'totalItems' => 1000
            ]
        ], 200)
    ]);

    $response = $this->get('/browse');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Browse')
        ->has('initialImages')
        ->has('currentPage')
        ->has('hasNextPage')
        ->has('nextCursor')
        ->where('currentPage', 1)
        ->where('hasNextPage', true)
        ->where('nextCursor', 'next_cursor_token')
    );

    // Verify that the CivitAI API was called correctly
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'civitai.com/api/v1/images') &&
               $request->data()['page'] === 1 &&
               $request->data()['limit'] === 20 &&
               $request->data()['sort'] === 'Newest' &&
               $request->data()['nsfw'] === 'false';
    });
});

it('transforms CivitAI images correctly', function () {
    // Mock the CivitAI API response with detailed image data
    Http::fake([
        'civitai.com/api/v1/images*' => Http::response([
            'items' => [
                [
                    'id' => 123456,
                    'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/detailed-image.jpeg',
                    'width' => 1024,
                    'height' => 1536,
                    'hash' => 'detailed_hash_456',
                    'meta' => [
                        'Model' => 'Realistic Vision V5.1',
                        'prompt' => 'detailed prompt with many words',
                        'seed' => 12345,
                        'steps' => 30,
                        'cfg_scale' => 7.5
                    ]
                ],
                [
                    'id' => 654321,
                    'url' => 'https://image.civitai.com/another/path/second-image.png',
                    'width' => 768,
                    'height' => 768,
                    'hash' => 'second_hash_789',
                    'meta' => [
                        'Model' => 'DreamShaper',
                        'prompt' => 'another test prompt'
                        // Note: missing seed to test null handling
                    ]
                ]
            ],
            'metadata' => [
                'nextCursor' => 'cursor_for_next_batch'
            ]
        ], 200)
    ]);

    $response = $this->get('/browse');

    $response->assertStatus(200);

    $data = $response->getOriginalContent()->getData();
    $images = $data['page']['props']['initialImages'];

    // Verify we have the correct number of images
    expect($images)->toHaveCount(2);

    // Test first image transformation
    $firstImage = $images[0];
    expect($firstImage)->toMatchArray([
        'id' => 'civitai-image-123456',
        'src' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/detailed-image.jpeg',
        'width' => 1024,
        'height' => 1536,
        'page' => 'page_1',
        'index' => 0,
        'meta' => [
            'model_name' => 'Realistic Vision V5.1',
            'model_id' => null,
            'version_name' => null,
            'blurhash' => 'detailed_hash_456',
            'prompt' => 'detailed prompt with many words',
            'seed' => 12345,
        ]
    ]);

    // Test second image transformation (with missing seed)
    $secondImage = $images[1];
    expect($secondImage)->toMatchArray([
        'id' => 'civitai-image-654321',
        'src' => 'https://image.civitai.com/another/path/second-image.png',
        'width' => 768,
        'height' => 768,
        'page' => 'page_1',
        'index' => 1,
        'meta' => [
            'model_name' => 'DreamShaper',
            'model_id' => null,
            'version_name' => null,
            'blurhash' => 'second_hash_789',
            'prompt' => 'another test prompt',
            'seed' => null, // Should handle missing seed gracefully
        ]
    ]);
});

it('handles cursor-based pagination correctly', function () {
    // Mock the CivitAI API response for cursor-based pagination
    Http::fake([
        'civitai.com/api/v1/images*' => Http::response([
            'items' => [
                [
                    'id' => 99999,
                    'url' => 'https://image.civitai.com/cursor/paginated-image.jpeg',
                    'width' => 512,
                    'height' => 512,
                    'hash' => 'cursor_hash',
                    'meta' => [
                        'Model' => 'Cursor Model',
                        'prompt' => 'cursor pagination test'
                    ]
                ]
            ],
            'metadata' => [
                'nextCursor' => 'new_cursor_token'
            ]
        ], 200)
    ]);

    $response = $this->get('/browse?cursor=existing_cursor_token');

    $response->assertStatus(200);

    // Verify that the CivitAI API was called with cursor instead of page
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'civitai.com/api/v1/images') &&
               $request->data()['cursor'] === 'existing_cursor_token' &&
               !isset($request->data()['page']); // Page should not be set when cursor is used
    });

    $data = $response->getOriginalContent()->getData();
    $images = $data['page']['props']['initialImages'];

    // Verify batch ID is cursor-based
    expect($images[0]['page'])->toBe('cursor_existing_cursor_token');
});

it('handles CivitAI API errors gracefully', function () {
    // Mock a failed API response
    Http::fake([
        'civitai.com/api/v1/images*' => Http::response([], 500)
    ]);

    $response = $this->get('/browse');

    // Should return 500 since the controller throws an exception
    $response->assertStatus(500);
});

it('handles empty CivitAI response', function () {
    // Mock an empty but successful API response
    Http::fake([
        'civitai.com/api/v1/images*' => Http::response([
            'items' => [],
            'metadata' => []
        ], 200)
    ]);

    $response = $this->get('/browse');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Browse')
        ->where('initialImages', [])
        ->where('hasNextPage', false)
        ->where('nextCursor', null)
    );
});

it('validates pagination parameters correctly', function () {
    Http::fake([
        'civitai.com/api/v1/images*' => Http::response([
            'items' => [],
            'metadata' => []
        ], 200)
    ]);

    // Test with string page parameter (should be cast to int)
    $response = $this->get('/browse?page=5');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->where('currentPage', 5)
    );

    // Verify API was called with correct page
    Http::assertSent(function ($request) {
        return $request->data()['page'] === 5;
    });
});

it('uses correct API parameters for CivitAI', function () {
    Http::fake([
        'civitai.com/api/v1/images*' => Http::response([
            'items' => [],
            'metadata' => []
        ], 200)
    ]);

    $this->get('/browse');

    // Verify all required parameters are sent to CivitAI API
    Http::assertSent(function ($request) {
        $data = $request->data();
        return $data['limit'] === 20 &&
               $data['sort'] === 'Newest' &&
               $data['period'] === 'AllTime' &&
               $data['nsfw'] === 'false';
    });
});
