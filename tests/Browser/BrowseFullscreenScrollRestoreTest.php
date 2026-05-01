<?php

use App\Models\File;
use App\Models\Tab;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function demoImageDataUri(string $label, string $color): string
{
    $svg = <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" width="800" height="1200" viewBox="0 0 800 1200">
            <rect width="800" height="1200" fill="{$color}" />
            <text x="400" y="600" text-anchor="middle" dominant-baseline="middle" fill="white" font-size="72" font-family="Arial, sans-serif">{$label}</text>
        </svg>
        SVG;

    return 'data:image/svg+xml;charset=UTF-8,'.rawurlencode($svg);
}

function browseSurfaceState($page): array
{
    return $page->script(<<<'JS'
        () => {
            const scroller = document.querySelector('[data-testid="vibe-list-scroll"]');
            const visibleLabels = Array.from(document.querySelectorAll('[data-testid="vibe-list-card-open"]'))
                .map((button) => {
                    const rect = button.getBoundingClientRect();

                    return {
                        label: button.getAttribute('aria-label'),
                        visible: rect.width > 50
                            && rect.height > 50
                            && rect.bottom > 80
                            && rect.top < window.innerHeight - 80,
                    };
                })
                .filter((entry) => entry.visible)
                .map((entry) => entry.label);

            return {
                fullscreenVisible: document.querySelector('[data-testid="vibe-fullscreen-surface"]')?.getAttribute('data-visible'),
                listVisible: document.querySelector('[data-testid="vibe-list-surface"]')?.getAttribute('data-visible'),
                path: window.location.pathname,
                scrollTop: scroller ? Math.round(scroller.scrollTop) : null,
                visibleLabels,
            };
        }
        JS);
}

function waitForBrowsePath($page, string $path): void
{
    $matched = $page->script(<<<JS
        async () => {
            for (let attempt = 0; attempt < 40; attempt += 1) {
                if (window.location.pathname === '{$path}') {
                    return true;
                }

                await new Promise((resolve) => window.setTimeout(resolve, 100));
            }

            return window.location.pathname;
        }
        JS);

    expect($matched)->toBeTrue("Expected browser path [{$path}] but received [{$matched}].");
}

function settleBrowseUi($page, int $milliseconds = 600): void
{
    $page->script(<<<JS
        async () => {
            await new Promise((resolve) => window.setTimeout(resolve, {$milliseconds}));
            return true;
        }
        JS);
}

it('preserves the browse grid scroll position when fullscreen closes', function () {
    $user = User::factory()->create();

    $files = collect(range(1, 24))->map(function (int $index) {
        $color = $index % 2 === 0 ? '#2563eb' : '#7c3aed';
        $imageUrl = demoImageDataUri("Item {$index}", $color);

        return File::factory()->create([
            'downloaded' => false,
            'ext' => 'svg',
            'listing_metadata' => [
                'height' => 1200,
                'width' => 800,
            ],
            'mime_type' => 'image/svg+xml',
            'path' => null,
            'poster_path' => null,
            'preview_path' => null,
            'preview_url' => $imageUrl,
            'source' => 'CivitAI',
            'title' => "Item {$index}",
            'url' => $imageUrl,
        ]);
    });

    $tab = Tab::factory()
        ->for($user)
        ->withParams([
            'feed' => 'online',
            'page' => 1,
            'service' => 'civit-ai-images',
            'tab_id' => 1,
        ])
        ->create([
            'is_active' => true,
            'label' => 'CivitAI',
            'position' => 0,
        ]);

    $tab->files()->sync(
        $files
            ->values()
            ->mapWithKeys(fn (File $file, int $position) => [$file->id => ['position' => $position]])
            ->all()
    );

    $page = $this->actingAs($user)
        ->visit('/browse')
        ->assertVisible('@vibe-list-scroll')
        ->assertNoJavascriptErrors();

    $scrollTopBeforeOpen = $page->script(<<<'JS'
        async () => {
            const scroller = document.querySelector('[data-testid="vibe-list-scroll"]');
            scroller.scrollTo({ top: 1400, behavior: 'auto' });
            scroller.dispatchEvent(new Event('scroll', { bubbles: true }));
            await new Promise((resolve) => window.setTimeout(resolve, 500));

            return Math.round(scroller.scrollTop);
        }
        JS);

    $stateBeforeOpen = browseSurfaceState($page);
    $targetLabel = $stateBeforeOpen['visibleLabels'][0] ?? null;

    expect($scrollTopBeforeOpen)->toBeGreaterThan(1000);
    expect($stateBeforeOpen['listVisible'])->toBe('true');
    expect($targetLabel)->not->toBeNull();

    $targetLabelJson = json_encode($targetLabel, JSON_THROW_ON_ERROR);
    $opened = $page->script(<<<JS
        () => {
            const targetLabel = {$targetLabelJson};
            const button = Array.from(document.querySelectorAll('[data-testid="vibe-list-card-open"]'))
                .find((candidate) => candidate.getAttribute('aria-label') === targetLabel);

            if (!button) {
                return false;
            }

            button.click();
            return true;
        }
        JS);

    expect($opened)->toBeTrue();

    preg_match('/(\d+)$/', (string) $targetLabel, $matches);
    $targetFileId = isset($matches[1]) ? (int) $matches[1] : null;

    expect($targetFileId)->not->toBeNull();

    waitForBrowsePath($page, "/browse/file/{$targetFileId}");
    settleBrowseUi($page);

    $stateInFullscreen = browseSurfaceState($page);

    expect($stateInFullscreen['fullscreenVisible'])->toBe('true');
    expect($stateInFullscreen['listVisible'])->toBe('false');

    $page->click('@vibe-back-to-list');
    waitForBrowsePath($page, '/browse');
    settleBrowseUi($page);

    $stateAfterExit = browseSurfaceState($page);

    expect($stateAfterExit['listVisible'])->toBe('true');
    expect($stateAfterExit['fullscreenVisible'])->toBe('false');
    expect($stateAfterExit['scrollTop'])->toBeGreaterThan(1000);
    expect(abs($stateAfterExit['scrollTop'] - $stateBeforeOpen['scrollTop']))->toBeLessThanOrEqual(80);
    expect($stateAfterExit['visibleLabels'])->toContain($stateBeforeOpen['visibleLabels'][0]);
});
