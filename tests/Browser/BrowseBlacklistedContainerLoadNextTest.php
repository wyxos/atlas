<?php

use App\Services\CivitAiImages;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

final class FakeCivitAiImagesBrowserService extends CivitAiImages
{
    private const PIXEL = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO2U0ZcAAAAASUVORK5CYII=';

    public static array $fetchLog = [];

    public static function resetLog(): void
    {
        self::$fetchLog = [];
    }

    public function fetch(array $params = []): array
    {
        $modelId = isset($params['modelId']) ? (int) $params['modelId'] : null;
        $modelVersionId = isset($params['modelVersionId']) ? (int) $params['modelVersionId'] : null;
        $page = $params['page'] ?? 1;

        $items = [];
        $nextCursor = null;

        if ($modelId === 101 && $modelVersionId === 202) {
            if ((string) $page === 'cursor-2') {
                $items = $this->pageItems(2000, 'page-2');
            } else {
                $items = $this->pageItems(1000, 'page-1');
                $nextCursor = 'cursor-2';
            }
        }

        self::$fetchLog[] = [
            'page' => $page,
            'modelId' => $modelId,
            'modelVersionId' => $modelVersionId,
            'item_ids' => array_map(static fn (array $item): int => $item['id'], $items),
            'usernames' => array_values(array_unique(array_map(static fn (array $item): string => $item['username'], $items))),
        ];

        return [
            'items' => $items,
            'metadata' => [
                'nextCursor' => $nextCursor,
            ],
        ];
    }

    public function transform(array $response, array $params = []): array
    {
        $now = CarbonImmutable::now();
        $items = is_array($response['items'] ?? null) ? $response['items'] : [];

        return [
            'files' => array_map(function (array $item) use ($now): array {
                $id = (int) $item['id'];
                $fileUrl = self::PIXEL."#file-{$id}";

                return [
                    'file' => [
                        'source' => self::SOURCE,
                        'source_id' => (string) $id,
                        'url' => $fileUrl,
                        'referrer_url' => "https://civitai.com/images/{$id}",
                        'filename' => "browser-test-{$id}.png",
                        'ext' => 'png',
                        'mime_type' => 'image/png',
                        'hash' => "hash-{$id}",
                        'title' => null,
                        'description' => null,
                        'preview_url' => $fileUrl,
                        'listing_metadata' => json_encode($item, JSON_THROW_ON_ERROR),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    'metadata' => [
                        'file_referrer_url' => "https://civitai.com/images/{$id}",
                        'payload' => json_encode([
                            'prompt' => $item['meta']['prompt'] ?? null,
                            'width' => $item['width'] ?? 1,
                            'height' => $item['height'] ?? 1,
                        ], JSON_THROW_ON_ERROR),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                ];
            }, $items),
            'filter' => [
                ...$this->defaultParams(),
                ...$params,
                'next' => $response['metadata']['nextCursor'] ?? null,
            ],
            'meta' => [
                'total' => 40,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pageItems(int $baseId, string $pageLabel): array
    {
        $items = [];

        for ($index = 1; $index <= 20; $index++) {
            $id = $baseId + $index;
            $username = $index <= 2 ? 'baraisgreat' : "{$pageLabel}-allowed-user-{$index}";

            $items[] = [
                'id' => $id,
                'url' => self::PIXEL,
                'username' => $username,
                'width' => 1,
                'height' => 1,
                'type' => 'image',
                'hash' => "hash-{$id}",
                'meta' => [
                    'prompt' => "{$pageLabel} prompt {$index}",
                ],
                'resource_containers' => [
                    [
                        'type' => 'Checkpoint',
                        'modelId' => 101,
                        'modelVersionId' => 202,
                        'referrerUrl' => 'https://civitai.com/models/101?modelVersionId=202',
                    ],
                ],
            ];
        }

        return $items;
    }
}

test('browse blacklists still apply when load next fetches the next civitai page after all visible items are reacted away', function () {
    FakeCivitAiImagesBrowserService::resetLog();
    app()->instance(CivitAiImages::class, new FakeCivitAiImagesBrowserService);

    $user = \App\Models\User::factory()->create();
    $this->actingAs($user);

    $page = visit('/browse');

    $page
        ->wait(1)
        ->assertScript("document.querySelector('[data-test=\"create-tab-button\"]') !== null")
        ->assertScript('window.axios !== undefined');

    $page->script(<<<'JS'
() => {
    window.__atlasBrowseDebug = { calls: [] };

    const originalGet = window.axios.get.bind(window.axios);

    window.axios.get = async (url, config) => {
        const response = await originalGet(url, config);
        const resolvedUrl = typeof url === 'string' ? url : String(url);

        if (resolvedUrl.includes('/api/browse')) {
            const items = Array.isArray(response?.data?.items) ? response.data.items : [];

            window.__atlasBrowseDebug.calls.push({
                url: resolvedUrl,
                itemIds: items.map((item) => item.id),
                items: items.map((item) => ({
                    id: item.id,
                    userSourceIds: Array.isArray(item.containers)
                        ? item.containers
                            .filter((container) => container?.type === 'User' && container?.source === 'CivitAI')
                            .map((container) => container.source_id)
                        : [],
                })),
                userSourceIds: items.flatMap((item) => Array.isArray(item.containers)
                    ? item.containers
                        .filter((container) => container?.type === 'User' && container?.source === 'CivitAI')
                        .map((container) => container.source_id)
                    : []),
            });
        }

        return response;
    };

    return true;
}
JS
    );

    $page
        ->click('@create-tab-button')
        ->wait(0.5)
        ->assertScript("document.querySelector('[data-test=\"new-tab-form\"]') !== null")
        ->click('@service-select-trigger')
        ->click('CivitAI Images')
        ->click('@play-button')
        ->wait(0.5);

    FakeCivitAiImagesBrowserService::resetLog();

    $page->script('(() => { window.__atlasBrowseDebug.calls = []; return true; })()');

    $page
        ->click('@filter-button')
        ->wait(0.2)
        ->type('[placeholder="The ID of a model to get images from."]', '101')
        ->type('[placeholder="The ID of a model version to get images from."]', '202')
        ->click('Apply')
        ->wait(0.8)
        ->assertScript("document.querySelector('[data-test=\"items-pill\"]')?.textContent?.includes('20') === true");

    $firstBrowseCalls = $page->script('window.__atlasBrowseDebug.calls');
    $visibleIdsBeforeBlacklist = $page->script(<<<'JS'
() => Array.from(document.querySelectorAll('[data-file-id]'))
    .map((element) => Number(element.getAttribute('data-file-id')))
    .sort((left, right) => left - right)
JS
    );
    $firstPageItems = collect($firstBrowseCalls[0]['items']);
    $blockedFirstPageIds = $firstPageItems
        ->filter(fn (array $item): bool => in_array('baraisgreat', $item['userSourceIds'], true))
        ->pluck('id')
        ->values()
        ->all();
    $allowedFirstPageIds = $firstPageItems
        ->reject(fn (array $item): bool => in_array('baraisgreat', $item['userSourceIds'], true))
        ->pluck('id')
        ->values()
        ->all();

    expect(FakeCivitAiImagesBrowserService::$fetchLog)->toHaveCount(1)
        ->and((string) FakeCivitAiImagesBrowserService::$fetchLog[0]['page'])->toBe('1')
        ->and(FakeCivitAiImagesBrowserService::$fetchLog[0]['item_ids'])->toHaveCount(20)
        ->and($firstBrowseCalls)->toHaveCount(1)
        ->and($firstBrowseCalls[0]['itemIds'])->toHaveCount(20)
        ->and($firstBrowseCalls[0]['userSourceIds'])->toContain('baraisgreat')
        ->and($blockedFirstPageIds)->toHaveCount(2)
        ->and($allowedFirstPageIds)->toHaveCount(18)
        ->and($visibleIdsBeforeBlacklist)->toContain($blockedFirstPageIds[0]);

    $page
        ->hover("[data-file-id=\"{$blockedFirstPageIds[0]}\"]")
        ->wait(0.2)
        ->click("[data-file-id=\"{$blockedFirstPageIds[0]}\"] button[aria-label=\"Ban container\"]")
        ->assertSee('Blacklist Container')
        ->click('button:has-text("dislike")')
        ->click('Immediate Blacklist')
        ->click('Confirm Blacklist')
        ->wait(0.8)
        ->assertScript("document.querySelector('[data-file-id=\"{$blockedFirstPageIds[0]}\"]') === null")
        ->assertScript("document.querySelector('[data-file-id=\"{$blockedFirstPageIds[1]}\"]') === null");

    foreach ($allowedFirstPageIds as $fileId) {
        $page
            ->hover("[data-file-id=\"{$fileId}\"]")
            ->wait(0.05)
            ->click("[data-file-id=\"{$fileId}\"] button[aria-label=\"Like\"]")
            ->wait(0.05);
    }

    $page->wait(1.2);

    $visibleIdsAfterLoadNext = $page->script(<<<'JS'
() => Array.from(document.querySelectorAll('[data-file-id]'))
    .map((element) => Number(element.getAttribute('data-file-id')))
    .sort((left, right) => left - right)
JS
    );

    $browseCallsAfterLoadNext = $page->script('window.__atlasBrowseDebug.calls');
    $secondPageItems = collect($browseCallsAfterLoadNext[1]['items']);
    $blockedSecondPageIds = $secondPageItems
        ->filter(fn (array $item): bool => in_array('baraisgreat', $item['userSourceIds'], true))
        ->pluck('id')
        ->values()
        ->all();
    $allowedSecondPageIds = $secondPageItems
        ->reject(fn (array $item): bool => in_array('baraisgreat', $item['userSourceIds'], true))
        ->pluck('id')
        ->values()
        ->all();
    $unexpectedVisibleIdsAfterLoadNext = array_values(array_diff($visibleIdsAfterLoadNext, $allowedSecondPageIds));

    expect(FakeCivitAiImagesBrowserService::$fetchLog)->toHaveCount(2)
        ->and(FakeCivitAiImagesBrowserService::$fetchLog[1]['page'])->toBe('cursor-2')
        ->and(FakeCivitAiImagesBrowserService::$fetchLog[1]['item_ids'])->toHaveCount(20)
        ->and(FakeCivitAiImagesBrowserService::$fetchLog[1]['usernames'])->toContain('baraisgreat')
        ->and($browseCallsAfterLoadNext)->toHaveCount(2)
        ->and($blockedSecondPageIds)->toBe([])
        ->and($allowedSecondPageIds)->toHaveCount(18)
        ->and($browseCallsAfterLoadNext[1]['itemIds'])->toHaveCount(18)
        ->and($browseCallsAfterLoadNext[1]['userSourceIds'])->not->toContain('baraisgreat')
        ->and($visibleIdsAfterLoadNext)->not->toBeEmpty()
        ->and($unexpectedVisibleIdsAfterLoadNext)->toBe([]);

    $page->assertNoJavaScriptErrors();
});
