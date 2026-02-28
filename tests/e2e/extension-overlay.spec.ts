import { expect, test } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

test('content script applies overlays and requests matches', async ({ page }) => {
    test.skip(process.env.RUN_EXTENSION_E2E !== '1', 'Set RUN_EXTENSION_E2E=1 to execute extension overlay E2E.');
    const contentScriptPath = path.resolve(process.cwd(), 'extension/dist/content.js');
    test.skip(!fs.existsSync(contentScriptPath), 'Build extension first (npm run build:extension)');

    await page.addInitScript(() => {
        const store = {
            atlasDomain: 'https://atlas-v2.test',
            apiToken: 'atlas_fixture_key',
            matchRules: [
                {
                    domain: 'deviantart.com',
                    regexes: ['/art/', 'images-wix'],
                },
            ],
        };

        (window as Window & { chrome?: unknown }).chrome = {
            runtime: {
                lastError: null,
            },
            storage: {
                local: {
                    get(_keys: unknown, callback: (result: Record<string, unknown>) => void) {
                        callback(store);
                    },
                },
            },
        };
    });

    await page.route('https://atlas-v2.test/api/extension/matches', async (route) => {
        const body = route.request().postDataJSON() as {
            items: Array<{ candidate_id: string; type: 'media' | 'referrer'; url: string }>;
        };

        const ids = Array.from(new Set((body.items ?? []).map((item) => item.candidate_id)));
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                matches: ids.map((id, index) => ({
                    id,
                    exists: true,
                    reaction: index % 2 === 0 ? 'love' : 'like',
                    reacted_at: null,
                    downloaded_at: null,
                    blacklisted_at: null,
                })),
            }),
        });
    });

    const fixturePath = path.resolve(process.cwd(), 'tests/e2e/fixtures/extension-overlay.html');
    await page.goto(`file://${fixturePath}`);
    await page.addScriptTag({ path: contentScriptPath });

    const wrappers = page.locator('[data-atlas-overlay-wrapper="1"]');
    await expect(wrappers).toHaveCount(2);

    const badges = page.locator('[data-atlas-overlay-badge="1"]');
    await expect(badges).toHaveCount(2);

    const firstBadge = badges.first();
    await expect(firstBadge).toHaveCSS('width', '50px');
    await expect(firstBadge).toHaveCSS('height', '50px');

    const firstMedia = page.locator('img[alt="anchored-media"]');
    await expect(firstMedia).toHaveCSS('opacity', '0.3');
});
