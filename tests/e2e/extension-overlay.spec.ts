import { expect, test, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

async function bootstrapExtensionFixture(page: Page): Promise<string> {
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
                    regexes: ['.*\\/art\\/.*', '.*images-wix.*'],
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

    return contentScriptPath;
}

test('content script applies overlays and requests matches', async ({ page }) => {
    const contentScriptPath = await bootstrapExtensionFixture(page);

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

test('standalone widget follows image to modal without extra synthetic clicks', async ({ page }) => {
    const contentScriptPath = await bootstrapExtensionFixture(page);

    const fixturePath = path.resolve(process.cwd(), 'tests/e2e/fixtures/extension-overlay-modal.html');
    await page.goto(`file://${fixturePath}`);
    await page.addScriptTag({ path: contentScriptPath });

    const smallImage = page.locator('img[alt="fixture-small"]');
    await smallImage.hover();

    const smallWrapper = page.locator('img[alt="fixture-small"]').locator('xpath=ancestor::*[@data-atlas-overlay-wrapper="1"][1]');
    const smallBar = smallWrapper.locator('[data-atlas-overlay-reaction-bar="1"]');
    await expect(smallBar).toHaveCSS('opacity', '1');

    await smallImage.click();
    await expect(page.locator('#modal.open')).toHaveCount(1);
    await expect.poll(async () => page.evaluate(() => (window as Window & { __zoomClicks: number }).__zoomClicks)).toBe(0);

    const largeImage = page.locator('img[alt="fixture-large"]');
    await page.mouse.move(0, 0);
    await largeImage.hover();
    await page.evaluate(() => {
        const wrapper = document.querySelector('img[alt="fixture-large"]')?.closest('[data-atlas-overlay-wrapper="1"]');
        wrapper?.dispatchEvent(new MouseEvent('mouseover', { bubbles: true }));
    });

    const largeWrapper = page.locator('img[alt="fixture-large"]').locator('xpath=ancestor::*[@data-atlas-overlay-wrapper="1"][1]');
    const largeBar = largeWrapper.locator('[data-atlas-overlay-reaction-bar="1"]');
    await expect(largeBar).toHaveCSS('opacity', '1');
    await expect(smallBar).toHaveCSS('opacity', '0');
});
