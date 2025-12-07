<?php

use App\Models\BrowseTab;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('test', function () {
    $this->actingAs(User::factory()->create());

    visit(url('/browse'))
        ->assertSeeIn('[data-test="no-tabs-message"]', 'Create a tab to start browsing')
        ->assertSeeIn('[data-test="create-tab-button"]', 'New Tab');
});

it('restores cursor values for a browsed tab after reload', function () {
    $user = User::factory()->create();

    // Persist a tab that already scrolled deep into CivitAI (cursor pagination)
    $tab = BrowseTab::factory()
        ->for($user)
        ->withQueryParams([
            'page' => 'cursor-x',
            'next' => 'cursor-y',
        ])
        ->create([
            'label' => 'Scrolled Tab',
        ]);

    // Attach 139 files in order to simulate a long masonry session
    $files = collect(range(1, 139))->map(fn ($i) => File::factory()->create([
        'referrer_url' => "https://example.com/file{$i}.jpg",
    ]));

    $tab->files()->sync(
        $files->pluck('id')->mapWithKeys(fn ($id, $index) => [$id => ['position' => $index]])
    );

    $this->actingAs($user);

    $page = visit('/browse')
        ->assertPresent('[data-test="pagination-info"]')
        ->assertSee('Scrolled Tab')
        ->assertNoJavascriptErrors();

    // Expected behaviour: masonry pills should reflect the saved cursor state
    $page->assertSeeIn('[data-test="page-pill"]', 'cursor-x')
        ->assertSeeIn('[data-test="next-pill"]', 'cursor-y')
        ->assertDontSeeIn('[data-test="page-pill"]', '1');
});