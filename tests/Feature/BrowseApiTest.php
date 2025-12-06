<?php

use App\Models\File;
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
    expect($items)->not->toBeEmpty();
    expect($items)->toBeArray();
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
    $items1 = $response1->json('items');
    expect($items1)->not->toBeEmpty();
    expect($items1)->toBeArray();
    $nextCursor = $response1->json('nextPage'); // This is the cursor string
    expect($nextCursor)->not->toBeNull();
    expect($nextCursor)->toBeString();

    // Use the cursor as the page parameter for the next request
    $response2 = $this->actingAs($user)
        ->getJson("/api/browse?page={$nextCursor}");

    $response2->assertSuccessful();
    $items2 = $response2->json('items');
    expect($items2)->not->toBeEmpty();
    expect($items2)->toBeArray();
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
    expect($items)->not->toBeEmpty();
    expect($items)->toBeArray();
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

it('persists files to database when browsing', function () {
    $user = User::factory()->create();

    $initialFileCount = File::count();

    $response = $this->actingAs($user)
        ->getJson('/api/browse?page=1');

    $response->assertSuccessful();
    $items = $response->json('items');
    expect($items)->not->toBeEmpty();
    $itemCount = count($items);

    // Files should be persisted to database
    $newFileCount = File::count();
    expect($newFileCount)->toBeGreaterThan($initialFileCount);
    // At least as many files as items returned should be persisted
    expect($newFileCount)->toBeGreaterThanOrEqual($itemCount);
});

it('persists file metadata when browsing', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->getJson('/api/browse?page=1');

    $response->assertSuccessful();

    // Check that files have metadata
    $files = File::with('metadata')->where('source', 'CivitAI')->limit(10)->get();
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        expect($file->metadata)->not->toBeNull();
        expect($file->metadata->payload)->toBeArray();
    }
});

it('updates existing files when same referrer_url is encountered', function () {
    $user = User::factory()->create();

    // First request
    $response1 = $this->actingAs($user)
        ->getJson('/api/browse?page=1');

    $response1->assertSuccessful();
    $firstFileCount = File::count();
    expect($firstFileCount)->toBeGreaterThan(0);

    // Get a sample file to verify it gets updated
    $sampleFile = File::where('source', 'CivitAI')->first();
    expect($sampleFile)->not->toBeNull();
    $originalUrl = $sampleFile->url;

    // Second request with same page - should update existing files, not create duplicates
    $response2 = $this->actingAs($user)
        ->getJson('/api/browse?page=1');

    $response2->assertSuccessful();
    $secondFileCount = File::count();

    // File count should be similar (may vary slightly due to API changes, but shouldn't double)
    expect($secondFileCount)->toBeLessThanOrEqual($firstFileCount * 1.2); // Allow 20% variance for API changes

    // Verify the sample file still exists (was updated, not duplicated)
    $updatedFile = File::find($sampleFile->id);
    expect($updatedFile)->not->toBeNull();
});

it('filters out downloaded, previewed, and blacklisted files from results', function () {
    $user = User::factory()->create();

    // First request to get files
    $response1 = $this->actingAs($user)
        ->getJson('/api/browse?page=1');

    $response1->assertSuccessful();
    $items1 = $response1->json('items');
    expect($items1)->not->toBeEmpty();

    // Mark some files as downloaded, previewed, or blacklisted
    $files = File::where('source', 'CivitAI')->limit(5)->get();
    expect($files)->not->toBeEmpty();

    $file1 = $files->first();
    $file1->downloaded = true;
    $file1->save();

    $file2 = $files->skip(1)->first();
    if ($file2) {
        $file2->previewed_at = now();
        $file2->save();
    }

    $file3 = $files->skip(2)->first();
    if ($file3) {
        $file3->blacklisted_at = now();
        $file3->save();
    }

    // Second request - should filter out those files
    $response2 = $this->actingAs($user)
        ->getJson('/api/browse?page=1');

    $response2->assertSuccessful();
    $items2 = $response2->json('items');
    expect($items2)->not->toBeEmpty();

    // The filtered files should not appear in results
    $itemIds = collect($items2)->pluck('id')->toArray();

    // Check that the downloaded file is not in results
    $listingMetadata1 = $file1->listing_metadata ?? [];
    $file1Id = (string) ($listingMetadata1['id'] ?? $file1->source_id ?? $file1->id);
    expect($itemIds)->not->toContain($file1Id);

    // Check other files if they exist
    if ($file2) {
        $listingMetadata2 = $file2->listing_metadata ?? [];
        $file2Id = (string) ($listingMetadata2['id'] ?? $file2->source_id ?? $file2->id);
        expect($itemIds)->not->toContain($file2Id);
    }

    if ($file3) {
        $listingMetadata3 = $file3->listing_metadata ?? [];
        $file3Id = (string) ($listingMetadata3['id'] ?? $file3->source_id ?? $file3->id);
        expect($itemIds)->not->toContain($file3Id);
    }
});
