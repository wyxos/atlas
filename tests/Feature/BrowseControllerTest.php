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
        ->has('items')
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
    $items = $data['page']['props']['items'];
    $previousCursor = $data['page']['props']['previousCursor'];

    // Verify previous cursor tracking
    expect($previousCursor)->toBe('existing_cursor_token');
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
        ->where('items', [])
        ->where('hasNextPage', false)
        ->where('nextCursor', null)
    );
});

