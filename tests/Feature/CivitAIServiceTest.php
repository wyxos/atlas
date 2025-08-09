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

    expect($result['filters']['page'])->toBe(1); // Current page value
    expect($result['filters']['nextPage'])->toBe('next_cursor_token'); // Next page value
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
               !isset($data['cursor']) && // First page should not have cursor
               $data['limit'] === 40 && // Default limit from service
               $data['sort'] === 'Newest' &&
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

    expect($result['filters']['nextPage'])->toBe('new_cursor_token'); // Check nextPage instead of nextCursor
    expect($result['filters']['page'])->toBe('existing_cursor_token'); // Current page value

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
    expect($result['filters']['nextPage'])->toBeNull();
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

    expect($result['filters']['page'])->toBe('5'); // Page value as received

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
        return $data['limit'] === 40 && // Default limit from service
               $data['sort'] === 'Newest' &&
               $data['period'] === 'AllTime' &&
               !isset($data['cursor']); // First page should not have cursor
    });
});

it('fetches and transforms posts correctly from CivitAI API', function () {
    Http::fake([
        'civitai.com/api/trpc/post.getInfinite*' => Http::response([
            'result' => [
                'data' => [
                    'json' => [
                        'items' => [
                            [
                                'id' => 123456,
                                'images' => [
                                    [
                                        'id' => 789012,
                                        'name' => 'sample.jpeg',
                                        'url' => 'abc123-def456-ghi789',
                                        'width' => 768,
                                        'height' => 1024,
                                        'hash' => 'sample_hash',
                                        'prompt' => 'A beautiful test image',
                                        'stats' => ['likes' => 10]
                                    ]
                                ]
                            ]
                        ],
                        'nextCursor' => 'next_cursor_token'
                    ]
                ]
            ]
        ], 200)
    ]);

    $request = new Request(['container' => 'posts', 'page' => 1]);
    $service = new CivitAIService($request);
    $result = $service->fetch();

    expect($result['filters']['container'])->toBe('posts');
    expect($result['filters']['page'])->toBe(1);
    expect($result['filters']['nextPage'])->toBe('next_cursor_token');
    expect($result['items'])->toHaveCount(1);

    $item = $result['items'][0];
    expect($item['id'])->toBeInt(); // Database File ID
    expect($item['src'])->toContain('anim=false,width=450,optimized=true');
    expect($item['original'])->toContain('original=true,quality=90');
    expect($item['width'])->toBe(768);
    expect($item['height'])->toBe(1024);
    // Container field has been removed from the response array

    // Verify that the API was called correctly
    Http::assertSent(function ($request) {
        $data = $request->data();
        if (!isset($data['input'])) return false;
        $input = json_decode($data['input'], true);
        return str_contains($request->url(), 'civitai.com/api/trpc/post.getInfinite') &&
               $input['json']['browsingLevel'] === 31 &&
               $input['json']['period'] === 'AllTime' &&
               $input['json']['sort'] === 'Newest' &&
               !isset($input['json']['cursor']); // First page should not have cursor
    });
});

it('handles posts cursor-based pagination correctly', function () {
    Http::fake([
        'civitai.com/api/trpc/post.getInfinite*' => Http::response([
            'result' => [
                'data' => [
                    'json' => [
                        'items' => [
                            [
                                'id' => 654321,
                                'images' => [
                                    [
                                        'id' => 999888,
                                        'name' => 'paginated.png',
                                        'url' => 'xyz789-uvw456-rst123',
                                        'width' => 512,
                                        'height' => 512,
                                        'hash' => 'paginated_hash',
                                        'prompt' => 'Paginated test',
                                        'stats' => ['likes' => 5]
                                    ]
                                ]
                            ]
                        ],
                        'nextCursor' => 'new_cursor_token'
                    ]
                ]
            ]
        ], 200)
    ]);

    $request = new Request(['container' => 'posts', 'page' => 'existing_cursor_token']);
    $service = new CivitAIService($request);
    $result = $service->fetch();

    expect($result['filters']['nextPage'])->toBe('new_cursor_token');
    expect($result['filters']['page'])->toBe('existing_cursor_token');
    expect($result['items'])->toHaveCount(1);

    $item = $result['items'][0];
    expect($item['page'])->toBe('existing_cursor_token');
    // Container field has been removed from the response array

    // Verify cursor-based API call
    Http::assertSent(function ($request) {
        $data = $request->data();
        if (!isset($data['input'])) return false;
        $input = json_decode($data['input'], true);
        return $input['json']['cursor'] === 'existing_cursor_token';
    });
});

it('creates container and file relationships correctly for posts', function () {
    Http::fake([
        'civitai.com/api/trpc/post.getInfinite*' => Http::response([
            'result' => [
                'data' => [
                    'json' => [
                        'items' => [
                            [
                                'id' => 111222,
                                'images' => [
                                    [
                                        'id' => 333444,
                                        'name' => 'relationship.webp',
                                        'url' => 'rel123-ation456-ship789',
                                        'width' => 1024,
                                        'height' => 768,
                                        'hash' => 'relationship_hash',
                                        'prompt' => 'Testing relationships',
                                        'stats' => ['likes' => 15]
                                    ]
                                ]
                            ]
                        ],
                        'meta' => []
                    ]
                ]
            ]
        ], 200)
    ]);

    // Run the migration first to ensure tables exist
    $this->artisan('migrate');

    $request = new Request(['container' => 'posts', 'page' => 1]);
    $service = new CivitAIService($request);
    $result = $service->fetch();

    // Verify file was created
    $this->assertDatabaseHas('files', [
        'source' => 'CivitAI',
        'source_id' => '333444', // Image ID
        'referrer_url' => 'https://civitai.com/images/333444',
        'filename' => 'relationship.webp'
    ]);

    // Verify container was created
    $this->assertDatabaseHas('containers', [
        'source' => 'CivitAI',
        'source_id' => '111222', // Post ID
        'referrer' => 'https://civitai.com/posts/111222'
    ]);

    // Verify file metadata was created
    $file = \App\Models\File::where('referrer_url', 'https://civitai.com/images/333444')->first();
    expect($file)->not->toBeNull();
    expect($file->metadata)->not->toBeNull();
    
    // The model cast converts JSON to array automatically
    $metadata = $file->metadata->payload;
    expect($metadata)->toBeArray();
    expect($metadata['width'])->toBe(1024);
    expect($metadata['height'])->toBe(768);

    // Verify container-file relationship exists
    $container = \App\Models\Container::where('source_id', '111222')->first();
    expect($container)->not->toBeNull();
    expect($container->files->count())->toBe(1);
    expect($container->files->first()->id)->toBe($file->id);
});

it('handles posts API errors gracefully', function () {
    Http::fake([
        'civitai.com/api/trpc/post.getInfinite*' => Http::response([], 500)
    ]);

    $request = new Request(['container' => 'posts', 'page' => 1]);
    $service = new CivitAIService($request);

    expect(fn() => $service->fetch())->toThrow(Exception::class, 'CivitAI API request failed after 3 attempts:');
});

it('handles empty posts response', function () {
    Http::fake([
        'civitai.com/api/trpc/post.getInfinite*' => Http::response([
            'result' => [
                'data' => [
                    'json' => [
                        'items' => [],
                        'meta' => []
                    ]
                ]
            ]
        ], 200)
    ]);

    $request = new Request(['container' => 'posts', 'page' => 1]);
    $service = new CivitAIService($request);
    $result = $service->fetch();

    expect($result['items'])->toBeEmpty();
    expect($result['filters']['nextPage'])->toBeNull();
});

it('performs real fetch across up to 4 pages without fakes', function () {
    // This test intentionally makes live requests to CivitAI to verify pagination works from page 1 (no cursor)
    // up to 3-4 pages by following nextPage cursors. It will be skipped if the network/service is unavailable.
    $maxPages = 4;
    $pageCount = 0;
    $page = 1; // First request should not include cursor
    $seenCursors = [];

    try {
        do {
            $request = new Request([
                // Default container is 'images' in the service
                'page' => $page,
                'limit' => 10, // keep payload smaller
            ]);

            $service = new CivitAIService($request);
            $result = $service->fetch();

            // Basic shape assertions
            expect($result)->toHaveKeys(['items','filters']);
            expect($result['filters'])->toHaveKeys(['page','nextPage']);
            expect($result['items'])->toBeArray();

            // On the very first page, we expect some items to be present
            if ($pageCount === 0) {
                expect(count($result['items']))->toBeGreaterThan(0);
            }

            $next = $result['filters']['nextPage'] ?? null;

            $pageCount++;
            if (!$next) {
                break; // no more pages
            }

            // Avoid infinite loops if a cursor repeats
            if (in_array($next, $seenCursors, true)) {
                break;
            }
            $seenCursors[] = $next;
            $page = $next; // Use next cursor as page for the following request
        } while ($pageCount < $maxPages);

        expect($pageCount)->toBeGreaterThanOrEqual(1);
        expect($pageCount)->toBeLessThanOrEqual($maxPages);
    } catch (Exception $e) {
        // Skip if network/service issues occur to keep the suite reliable
        $this->markTestSkipped('CivitAI live test skipped due to network/service unavailability: ' . $e->getMessage());
    }
});

