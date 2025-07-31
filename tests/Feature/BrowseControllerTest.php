<?php

use App\Jobs\DownloadFile;
use App\Models\File;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

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

    $response = $this->get('/browse/data');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Browse')
        ->has('items')
        ->has('filters')
        ->where('filters.page', 1) // First page is 1
        ->where('filters.nextPage', 'next_cursor_token')
        ->where('filters.sort', 'Most Reactions')
        ->where('filters.period', 'AllTime')
        ->where('filters.limit', 40)
        ->where('filters.nsfw', false)
        ->where('filters.autoNext', false)
        ->where('filters.container', 'images')
    );

    // Verify that the CivitAI API was called correctly
    Http::assertSent(function ($request) {
        $data = $request->data();
        return str_contains($request->url(), 'civitai.com/api/v1/images') &&
               $data['limit'] === 40 && // Default limit from service
               $data['sort'] === 'Newest' &&
               $data['period'] === 'AllTime' &&
               !isset($data['cursor']); // First request should not have cursor
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

    $response = $this->get('/browse/data?page=existing_cursor_token');

    $response->assertStatus(200);

    // Verify that the CivitAI API was called with cursor parameter
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'civitai.com/api/v1/images') &&
               $request->data()['cursor'] === 'existing_cursor_token';
    });

    // Verify response structure for cursor-based pagination
    $response->assertInertia(fn ($page) => $page
        ->component('Browse')
        ->has('items')
        ->has('filters')
        ->where('filters.nextPage', 'new_cursor_token')
        ->where('filters.page', 'existing_cursor_token')
    );

    // Verify the page attribute is correctly set for cursor-based pagination
    $data = $response->getOriginalContent()->getData();
    $items = $data['page']['props']['items'];
    expect($items[0]['page'])->toBe('existing_cursor_token');
});

it('handles CivitAI API errors gracefully', function () {
    // Mock a failed API response
    Http::fake([
        'civitai.com/api/v1/images*' => Http::response([], 500)
    ]);

    $response = $this->get('/browse/data');

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

    $response = $this->get('/browse/data');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Browse')
        ->where('items', [])
        ->where('filters.nextPage', null)
    );
});

it('can blacklist a file via POST request', function () {
    // Create a file to blacklist
    $file = File::factory()->create([
        'is_blacklisted' => false,
        'blacklist_reason' => null,
    ]);

    $response = $this->postJson(route('browse.blacklist', $file), [
        'reason' => 'Test blacklisting reason'
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'Item has been blacklisted'
        ]);

    // Verify the file was blacklisted in the database
    $file->refresh();
    expect($file->is_blacklisted)->toBeTrue();
    expect($file->blacklist_reason)->toBe('Test blacklisting reason');
});

it('can blacklist a file without a reason', function () {
    // Create a file to blacklist
    $file = File::factory()->create([
        'is_blacklisted' => false,
        'blacklist_reason' => null,
    ]);

    $response = $this->postJson(route('browse.blacklist', $file));

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'Item has been blacklisted'
        ]);

    // Verify the file was blacklisted in the database
    $file->refresh();
    expect($file->is_blacklisted)->toBeTrue();
    expect($file->blacklist_reason)->toBeNull();
});

it('validates blacklist reason length', function () {
    $file = File::factory()->create([
        'is_blacklisted' => false,
    ]);

    $longReason = str_repeat('a', 256); // Exceeds 255 char limit

    $response = $this->postJson(route('browse.blacklist', $file), [
        'reason' => $longReason
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    // Verify the file was NOT blacklisted
    $file->refresh();
    expect($file->is_blacklisted)->toBeFalse();
});

it('can queue a file for download via POST request', function () {
    Queue::fake();
    
    // Create a file to download
    $file = File::factory()->create([
        'downloaded' => false,
        'download_progress' => 0,
    ]);

    $response = $this->postJson(route('browse.download', $file));

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'File download started'
        ]);

    // Assert that the DownloadFile job was dispatched
    Queue::assertPushed(DownloadFile::class, function ($job) use ($file) {
        return $job->file->id === $file->id;
    });
});

it('returns 404 when trying to download non-existent file', function () {
    $response = $this->postJson(route('browse.download', ['file' => 99999]));

    $response->assertStatus(404);
});

it('handles empty results with next cursor scenario for autocycle', function () {
    // Mock the CivitAI API response with empty items but next cursor
    Http::fake([
        'civitai.com/api/v1/images*' => Http::response([
            'items' => [],
            'metadata' => [
                'nextCursor' => 'cursor_with_potential_items',
                'totalItems' => 1000
            ]
        ], 200)
    ]);

    $response = $this->get('/browse/data');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Browse')
        ->where('items', [])
        ->where('filters.nextPage', 'cursor_with_potential_items')
    );

    // This scenario should trigger the autocycle prompt in the frontend
    // The response contains empty items but has nextPage available
    $data = $response->getOriginalContent()->getData();
    $props = $data['page']['props'];
    
    expect($props['items'])->toBeEmpty();
    expect($props['filters']['nextPage'])->toBe('cursor_with_potential_items');
});

it('handles autoNext parameter correctly', function () {
    // Mock the CivitAI API response
    Http::fake([
        'civitai.com/api/v1/images*' => Http::response([
            'items' => [],
            'metadata' => []
        ], 200)
    ]);

    // Test with autoNext=true
    $response = $this->get('/browse/data?autoNext=true');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Browse')
        ->has('filters')
        ->where('filters.autoNext', true)
    );

    // Test with autoNext=false
    $response = $this->get('/browse/data?autoNext=false');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Browse')
        ->has('filters')
        ->where('filters.autoNext', false)
    );
});

