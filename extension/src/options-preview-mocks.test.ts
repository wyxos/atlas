import { beforeEach, describe, expect, it, vi } from 'vitest';

describe('options preview mocks', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
    });

    it('installs a chrome runtime and storage fixture for the real options app', async () => {
        const { installExtensionOptionsPreviewMocks } = await import('./options-preview-mocks');

        installExtensionOptionsPreviewMocks();

        const manifest = chrome.runtime.getManifest();
        expect(manifest.version).toBeTruthy();

        await expect(new Promise<Record<string, unknown>>((resolve) => {
            chrome.storage.local.get(['atlasDomain', 'apiToken', 'siteCustomizations'], resolve);
        })).resolves.toMatchObject({
            atlasDomain: 'https://atlas.test',
            apiToken: expect.any(String),
            siteCustomizations: expect.arrayContaining([
                expect.objectContaining({
                    domain: 'civitai.com',
                }),
            ]),
        });

        await expect(new Promise<unknown>((resolve) => {
            chrome.runtime.sendMessage({ type: 'ATLAS_GET_DOWNLOAD_PROGRESS_DEBUG_STATE' }, resolve);
        })).resolves.toMatchObject({
            ok: true,
            snapshot: expect.objectContaining({
                connectionState: 'connected',
                subscriberTabCount: expect.any(Number),
            }),
        });
    });
});
