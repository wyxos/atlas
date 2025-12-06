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
    expect($response->json('nextPage'))->toBe(2);
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
    expect($firstItem['width'])->toBeGreaterThanOrEqual(200);
    expect($firstItem['width'])->toBeLessThanOrEqual(500);
    expect($firstItem['height'])->toBeGreaterThanOrEqual(200);
    expect($firstItem['height'])->toBeLessThanOrEqual(500);
    expect($firstItem['type'])->toBeIn(['image', 'video']);
    expect($firstItem['page'])->toBe(1);
});

it('supports pagination', function () {
    $user = User::factory()->create();

    $response1 = $this->actingAs($user)
        ->getJson('/api/browse?page=1');

    $response1->assertSuccessful();
    expect($response1->json('items'))->toHaveCount(40);
    expect($response1->json('nextPage'))->toBe(2);

    $response2 = $this->actingAs($user)
        ->getJson('/api/browse?page=2');

    $response2->assertSuccessful();
    expect($response2->json('items'))->toHaveCount(40);
    expect($response2->json('nextPage'))->toBe(3);

    // Verify items are different between pages
    $items1 = $response1->json('items');
    $items2 = $response2->json('items');
    expect($items1[0]['id'])->not->toBe($items2[0]['id']);
});

it('returns null for nextPage on last page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->getJson('/api/browse?page=100');

    $response->assertSuccessful();
    expect($response->json('nextPage'))->toBeNull();
});

it('defaults to page 1 when page parameter is missing', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->getJson('/api/browse');

    $response->assertSuccessful();
    $items = $response->json('items');
    expect($items)->toHaveCount(40);
    expect($items[0]['page'])->toBe(1);
    expect($response->json('nextPage'))->toBe(2);
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
