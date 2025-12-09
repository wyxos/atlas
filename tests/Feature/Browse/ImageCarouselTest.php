<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('displays carousel when image is clicked', function () {
    $user = User::factory()->create();

    // Mock external service calls
    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'civitai.com/api/v1/images')) {
            if (str_contains($url, 'limit=1')) {
                return Http::response([
                    'items' => [],
                    'metadata' => ['nextCursor' => null],
                ], 200);
            }

            // Return multiple items for carousel testing
            return Http::response([
                'items' => collect(range(1, 10))->map(fn ($i) => [
                    'id' => $i,
                    'url' => "https://image.civitai.com/test/image{$i}.png",
                    'meta' => ['width' => 640, 'height' => 640],
                    'width' => 640,
                    'height' => 640,
                    'type' => 'image',
                    'hash' => "hash{$i}",
                ])->toArray(),
                'metadata' => ['nextCursor' => 'cursor-2'],
            ], 200);
        }

        return Http::response(['items' => [], 'metadata' => ['nextCursor' => null]], 200);
    });

    $this->actingAs($user);

    $page = visit('/browse')
        ->click('@create-tab-button')
        ->wait(2)
        ->click('@service-select-trigger')
        ->wait(1)
        ->click('@service-select-item')
        ->wait(0.5)
        ->click('@apply-service-button')
        ->wait(2)
        ->assertPresent('[data-test="masonry-component"]');

    // Click on an image to open the viewer
    $page->click('[data-test^="masonry-item-"]')
        ->wait(1)
        ->assertPresent('[data-test="image-carousel"]');
});

it('carousel slides smoothly when clicking items 1-6', function () {
    $user = User::factory()->create();

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'civitai.com/api/v1/images')) {
            if (str_contains($url, 'limit=1')) {
                return Http::response([
                    'items' => [],
                    'metadata' => ['nextCursor' => null],
                ], 200);
            }

            return Http::response([
                'items' => collect(range(1, 10))->map(fn ($i) => [
                    'id' => $i,
                    'url' => "https://image.civitai.com/test/image{$i}.png",
                    'meta' => ['width' => 640, 'height' => 640],
                    'width' => 640,
                    'height' => 640,
                    'type' => 'image',
                    'hash' => "hash{$i}",
                ])->toArray(),
                'metadata' => ['nextCursor' => 'cursor-2'],
            ], 200);
        }

        return Http::response(['items' => [], 'metadata' => ['nextCursor' => null]], 200);
    });

    $this->actingAs($user);

    $page = visit('/browse')
        ->click('@create-tab-button')
        ->wait(2)
        ->click('@service-select-trigger')
        ->wait(1)
        ->click('@service-select-item')
        ->wait(0.5)
        ->click('@apply-service-button')
        ->wait(2)
        ->click('[data-test^="masonry-item-"]')
        ->wait(1)
        ->assertPresent('[data-test="image-carousel"]');

    // Click through carousel items 1-6
    for ($i = 1; $i <= 6; $i++) {
        $page->click("[data-test='carousel-box-{$i}']")
            ->wait(0.6); // Wait for animation
    }

    $page->assertNoJavascriptErrors();
});

it('carousel slides smoothly when clicking items 7+ without flickering', function () {
    $user = User::factory()->create();

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'civitai.com/api/v1/images')) {
            if (str_contains($url, 'limit=1')) {
                return Http::response([
                    'items' => [],
                    'metadata' => ['nextCursor' => null],
                ], 200);
            }

            // Return at least 10 items to test items 7+
            return Http::response([
                'items' => collect(range(1, 15))->map(fn ($i) => [
                    'id' => $i,
                    'url' => "https://image.civitai.com/test/image{$i}.png",
                    'meta' => ['width' => 640, 'height' => 640],
                    'width' => 640,
                    'height' => 640,
                    'type' => 'image',
                    'hash' => "hash{$i}",
                ])->toArray(),
                'metadata' => ['nextCursor' => 'cursor-2'],
            ], 200);
        }

        return Http::response(['items' => [], 'metadata' => ['nextCursor' => null]], 200);
    });

    $this->actingAs($user);

    $page = visit('/browse')
        ->click('@create-tab-button')
        ->wait(2)
        ->click('@service-select-trigger')
        ->wait(1)
        ->click('@service-select-item')
        ->wait(0.5)
        ->click('@apply-service-button')
        ->wait(2)
        ->click('[data-test^="masonry-item-"]')
        ->wait(1)
        ->assertPresent('[data-test="image-carousel"]');

    // Navigate to item 6 first
    $page->click('[data-test="carousel-box-6"]')
        ->wait(0.6);

    // Click item 7 - this should slide smoothly without flickering
    $page->click('[data-test="carousel-box-7"]')
        ->wait(0.6)
        ->assertNoJavascriptErrors();

    // Continue clicking items 8, 9, 10 - all should slide smoothly
    for ($i = 8; $i <= 10; $i++) {
        $page->click("[data-test='carousel-box-{$i}']")
            ->wait(0.6);
    }

    $page->assertNoJavascriptErrors()
        ->assertNoConsoleLogs();
});

it('carousel navigation buttons work correctly', function () {
    $user = User::factory()->create();

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'civitai.com/api/v1/images')) {
            if (str_contains($url, 'limit=1')) {
                return Http::response([
                    'items' => [],
                    'metadata' => ['nextCursor' => null],
                ], 200);
            }

            return Http::response([
                'items' => collect(range(1, 10))->map(fn ($i) => [
                    'id' => $i,
                    'url' => "https://image.civitai.com/test/image{$i}.png",
                    'meta' => ['width' => 640, 'height' => 640],
                    'width' => 640,
                    'height' => 640,
                    'type' => 'image',
                    'hash' => "hash{$i}",
                ])->toArray(),
                'metadata' => ['nextCursor' => 'cursor-2'],
            ], 200);
        }

        return Http::response(['items' => [], 'metadata' => ['nextCursor' => null]], 200);
    });

    $this->actingAs($user);

    $page = visit('/browse')
        ->click('@create-tab-button')
        ->wait(2)
        ->click('@service-select-trigger')
        ->wait(1)
        ->click('@service-select-item')
        ->wait(0.5)
        ->click('@apply-service-button')
        ->wait(2)
        ->click('[data-test^="masonry-item-"]')
        ->wait(1)
        ->assertPresent('[data-test="image-carousel"]')
        ->assertPresent('[data-test="carousel-next-button"]')
        ->assertPresent('[data-test="carousel-previous-button"]');

    // Test next button
    $page->click('@carousel-next-button')
        ->wait(0.6)
        ->assertNoJavascriptErrors();

    // Test previous button
    $page->click('@carousel-previous-button')
        ->wait(0.6)
        ->assertNoJavascriptErrors();
});

it('hides empty boxes when near the end of items', function () {
    $user = User::factory()->create();

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'civitai.com/api/v1/images')) {
            if (str_contains($url, 'limit=1')) {
                return Http::response([
                    'items' => [],
                    'metadata' => ['nextCursor' => null],
                ], 200);
            }

            // Return exactly 8 items to test empty box hiding
            return Http::response([
                'items' => collect(range(1, 8))->map(fn ($i) => [
                    'id' => $i,
                    'url' => "https://image.civitai.com/test/image{$i}.png",
                    'meta' => ['width' => 640, 'height' => 640],
                    'width' => 640,
                    'height' => 640,
                    'type' => 'image',
                    'hash' => "hash{$i}",
                ])->toArray(),
                'metadata' => ['nextCursor' => null],
            ], 200);
        }

        return Http::response(['items' => [], 'metadata' => ['nextCursor' => null]], 200);
    });

    $this->actingAs($user);

    $page = visit('/browse')
        ->click('@create-tab-button')
        ->wait(2)
        ->click('@service-select-trigger')
        ->wait(1)
        ->click('@service-select-item')
        ->wait(0.5)
        ->click('@apply-service-button')
        ->wait(2)
        ->click('[data-test^="masonry-item-"]')
        ->wait(1)
        ->assertPresent('[data-test="image-carousel"]');

    // Navigate to 4th item before last (index 4 out of 8 items, so item 5)
    // Should not show empty boxes
    $page->click('[data-test="carousel-box-4"]')
        ->wait(0.6);

    // Navigate to 5th item before last (index 3 out of 8 items, so item 4)
    // Should not show empty boxes
    $page->click('[data-test="carousel-box-3"]')
        ->wait(0.6)
        ->assertNoJavascriptErrors();
});

it('only triggers loading when clicking on the last item', function () {
    $user = User::factory()->create();
    $loadMoreCalled = false;

    Http::fake(function ($request) use (&$loadMoreCalled) {
        $url = $request->url();

        if (str_contains($url, 'civitai.com/api/v1/images')) {
            if (str_contains($url, 'limit=1')) {
                return Http::response([
                    'items' => [],
                    'metadata' => ['nextCursor' => null],
                ], 200);
            }

            // First request - return 10 items with nextCursor
            if (!str_contains($url, 'cursor-2')) {
                return Http::response([
                    'items' => collect(range(1, 10))->map(fn ($i) => [
                        'id' => $i,
                        'url' => "https://image.civitai.com/test/image{$i}.png",
                        'meta' => ['width' => 640, 'height' => 640],
                        'width' => 640,
                        'height' => 640,
                        'type' => 'image',
                        'hash' => "hash{$i}",
                    ])->toArray(),
                    'metadata' => ['nextCursor' => 'cursor-2'],
                ], 200);
            }

            // Second request - return more items
            $loadMoreCalled = true;
            return Http::response([
                'items' => collect(range(11, 15))->map(fn ($i) => [
                    'id' => $i,
                    'url' => "https://image.civitai.com/test/image{$i}.png",
                    'meta' => ['width' => 640, 'height' => 640],
                    'width' => 640,
                    'height' => 640,
                    'type' => 'image',
                    'hash' => "hash{$i}",
                ])->toArray(),
                'metadata' => ['nextCursor' => null],
            ], 200);
        }

        return Http::response(['items' => [], 'metadata' => ['nextCursor' => null]], 200);
    });

    $this->actingAs($user);

    $page = visit('/browse')
        ->click('@create-tab-button')
        ->wait(2)
        ->click('@service-select-trigger')
        ->wait(1)
        ->click('@service-select-item')
        ->wait(0.5)
        ->click('@apply-service-button')
        ->wait(2)
        ->click('[data-test^="masonry-item-"]')
        ->wait(1)
        ->assertPresent('[data-test="image-carousel"]');

    // Navigate to second-to-last item (index 8 out of 10 items)
    // Should NOT trigger loading
    $page->click('[data-test="carousel-box-8"]')
        ->wait(1);

    // Verify loading was NOT triggered
    expect($loadMoreCalled)->toBeFalse();

    // Navigate to last item (index 9 out of 10 items)
    // Should trigger loading
    $page->click('[data-test="carousel-box-9"]')
        ->wait(2); // Wait for loading to complete

    // Verify loading was triggered
    expect($loadMoreCalled)->toBeTrue();

    $page->assertNoJavascriptErrors();
});
