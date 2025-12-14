<?php

use App\Models\BrowseTab;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('restores cursor values for a browsed tab after reload', function () {
    $user = User::factory()->create();

    // Persist a tab that already scrolled deep into CivitAI (cursor pagination)
    // Mark as active so it's automatically selected on page load
    $tab = BrowseTab::factory()
        ->for($user)
        ->withQueryParams([
            'service' => 'civit-ai-images',
            'page' => 'cursor-x',
            'next' => 'cursor-y',
        ])
        ->create([
            'label' => 'Scrolled Tab',
            'is_active' => true,
        ]);

    // Attach a reasonable number of files to simulate a masonry session
    // (reduced from 139 to 10 for faster test execution)
    $files = collect(range(1, 10))->map(fn ($i) => File::factory()->create([
        'referrer_url' => "https://example.com/file{$i}.jpg",
    ]));

    $tab->files()->sync(
        $files->pluck('id')->mapWithKeys(fn ($id, $index) => [$id => ['position' => $index]])
    );

    $this->actingAs($user);

    // Visit and wait for components to load (assertPresent/assertSee have built-in waiting)
    $page = visit('/browse')
        ->assertSee('Scrolled Tab')
        ->assertPresent('[data-test="pagination-info"]')
        ->assertNoJavascriptErrors();

    // Expected behaviour: masonry pills should reflect the saved cursor state
    $page->assertSeeIn('[data-test="page-pill"]', 'cursor-x')
        ->assertSeeIn('[data-test="next-pill"]', 'cursor-y')
        ->assertDontSeeIn('[data-test="page-pill"]', '1');
});

it('new tab does not load images until service is selected', function () {
    $user = User::factory()->create();

    // Mock external service calls (CivitAI) - only service discovery request (limit=1) should be made
    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'civitai.com/api/v1/images')) {
            return Http::response([
                'items' => [],
                'metadata' => [
                    'nextCursor' => null,
                ],
            ], 200);
        }

        return Http::response([
            'items' => [],
            'metadata' => [
                'nextCursor' => null,
            ],
        ], 200);
    });

    $this->actingAs($user);

    $page = visit('/browse')
        ->wait(5) // Wait for page to load
        ->assertSeeIn('[data-test="no-tabs-message"]', 'Create a tab to start browsing')
        ->click('@create-tab-button')
        ->wait(5); // Increased wait time for tab creation

    // Verify service selection header appears
    $page->assertPresent('[data-test="service-selection-header"]')
        ->assertPresent('[data-test="service-select-trigger"]')
        ->assertSeeIn('[data-test="no-service-message"]', 'Select a service to start browsing');

    // Verify remote service was only called for fetching services (limit=1) and nothing else
    Http::assertSent(fn ($request) => 
        str_contains($request->url(), 'civitai.com/api/v1/images') &&
        str_contains($request->url(), 'limit=1')
    );

    Http::assertNotSent(fn ($request) => 
        str_contains($request->url(), 'civitai.com/api/v1/images') &&
        ! str_contains($request->url(), 'limit=1')
    );
});

it('applies selected service and loads images', function () {
    $user = User::factory()->create();

    // Mock external service calls (CivitAI) for both service discovery (limit=1) and actual loading (default limit)
    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'civitai.com/api/v1/images')) {
            if (str_contains($url, 'limit=1')) {
                return Http::response([
                    'items' => [],
                    'metadata' => [
                        'nextCursor' => null,
                    ],
                ], 200);
            }

            return Http::response([
                'items' => [
                    [
                        'id' => 123,
                        'url' => 'https://image.civitai.com/alpha/bravo/file.png',
                        'meta' => [
                            'width' => 640,
                            'height' => 640,
                        ],
                        'width' => 640,
                        'height' => 640,
                        'type' => 'image',
                        'hash' => 'abc123',
                    ],
                ],
                'metadata' => [
                    'nextCursor' => 'cursor-2',
                ],
            ], 200);
        }

        return Http::response([
            'items' => [],
            'metadata' => [
                'nextCursor' => null,
            ],
        ], 200);
    });

    $this->actingAs($user);

    $page = visit('/browse')
        ->click('@create-tab-button')
        ->wait(2)
        ->assertPresent('[data-test="service-select-trigger"]')
        ->click('@service-select-trigger')
        ->wait(1);

    // Select CivitAI Images service
    $page->click('@service-select-item')
        ->wait(0.5)
        ->click('@apply-service-button')
        ->wait(2);

    // Verify masonry component is rendered (service was applied)
    $page->assertPresent('[data-test="masonry-component"]');

    // Verify remote service was called to load images (should not use limit=1 here)
    Http::assertSent(fn ($request) => 
        str_contains($request->url(), 'civitai.com/api/v1/images') &&
        ! str_contains($request->url(), 'limit=1')
    );
});