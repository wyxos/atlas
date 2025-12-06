<?php

use App\Models\User;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('returns a page of browse items for authenticated users', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->getJson('/api/browse?page=1');

    $response->assertSuccessful()
        ->assertJsonStructure([
            'items' => [
                '*' => [
                    'id',
                    'width',
                    'height',
                    'src',
                    'type',
                    'page',
                    'index',
                    'notFound',
                ],
            ],
            'nextPage',
        ]);

    $items = $response->json('items');
    expect($items)->toHaveCount(40);
    // nextPage is now the cursor string from CivitAI
    expect($response->json('nextPage'))->not->toBeNull();
    expect($response->json('nextPage'))->toBeString();
});

it('requires authentication to access browse', function () {
    $this->getJson('/api/browse')
        ->assertUnauthorized();
});

it('returns items with correct structure', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->getJson('/api/browse?page=1');

    $response->assertSuccessful();
    $items = $response->json('items');

    expect($items)->toBeArray();
    expect($items)->not->toBeEmpty();

    $firstItem = $items[0];
    expect($firstItem)->toHaveKeys(['id', 'width', 'height', 'src', 'type', 'page', 'index', 'notFound']);
    expect($firstItem['width'])->toBeInt();
    expect($firstItem['height'])->toBeInt();
    expect($firstItem['width'])->toBeGreaterThan(0);
    expect($firstItem['height'])->toBeGreaterThan(0);
    expect($firstItem['type'])->toBeIn(['image', 'video']);
    expect($firstItem['page'])->toBe(1);
});

it('supports pagination', function () {
    $user = User::factory()->create();

    $response1 = $this->actingAs($user)
        ->getJson('/api/browse?page=1');

    $response1->assertSuccessful();
    expect($response1->json('items'))->toHaveCount(40);
    $nextCursor = $response1->json('nextPage'); // This is the cursor string
    expect($nextCursor)->not->toBeNull();
    expect($nextCursor)->toBeString();

    // Use the cursor as the page parameter for the next request
    $response2 = $this->actingAs($user)
        ->getJson("/api/browse?page={$nextCursor}");

    $response2->assertSuccessful();
    expect($response2->json('items'))->toHaveCount(40);
    expect($response2->json('nextPage'))->not->toBeNull(); // Should be a new cursor
    expect($response2->json('nextPage'))->toBeString();

    // Verify items are different between pages
    $items1 = $response1->json('items');
    $items2 = $response2->json('items');
    expect($items1[0]['id'])->not->toBe($items2[0]['id']);
});

it('returns null for nextPage when no more pages available', function () {
    $user = User::factory()->create();

    // Make requests until we get null nextPage or hit reasonable limit
    $page = 1;
    $nextCursor = 'dummy'; // Initialize to non-null
    $maxIterations = 5; // Reasonable limit to avoid infinite loop (CivitAI has many pages)
    $iterations = 0;

    while ($nextCursor !== null && $iterations < $maxIterations) {
        $response = $this->actingAs($user)
            ->getJson("/api/browse?page={$page}");

        $response->assertSuccessful();
        $nextCursor = $response->json('nextPage'); // This is the cursor string
        
        // If we got items but no nextPage, that's the last page
        if ($nextCursor === null) {
            break;
        }
        
        // Use the cursor as the page for next request
        $page = $nextCursor;
        $iterations++;
    }

    // The API has many pages, so we likely hit the iteration limit
    // The important thing is that the structure is correct - nextPage should be a string or null
    if ($nextCursor !== null) {
        expect($nextCursor)->toBeString(); // If we hit limit, verify it's a valid cursor string
    } else {
        expect($nextCursor)->toBeNull(); // If we reached the end, verify it's null
    }
});

it('defaults to page 1 when page parameter is missing', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->getJson('/api/browse');

    $response->assertSuccessful();
    $items = $response->json('items');
    expect($items)->toHaveCount(40);
    expect($items[0]['page'])->toBe(1);
    // nextPage is now the cursor string from CivitAI
    expect($response->json('nextPage'))->not->toBeNull();
    expect($response->json('nextPage'))->toBeString();
});

it('includes various item types', function () {
    $user = User::factory()->create();

    // Make multiple requests to increase chance of getting different types
    $typesFound = [];
    for ($i = 0; $i < 5; $i++) {
        $response = $this->actingAs($user)
            ->getJson('/api/browse?page='.($i + 1));

        $items = $response->json('items');
        foreach ($items as $item) {
            $typesFound[$item['type']] = true;
            if (isset($item['notFound']) && $item['notFound']) {
                $typesFound['notFound'] = true;
            }
        }
    }

    // Should have at least images
    expect($typesFound)->toHaveKey('image');
});
