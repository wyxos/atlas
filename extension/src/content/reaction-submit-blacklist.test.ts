import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockGetStoredOptions = vi.fn();

vi.mock('../atlas-options', () => ({
    getStoredOptions: mockGetStoredOptions,
}));

describe('submitBadgeReaction blacklist', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
        history.replaceState({}, '', '/extension-test/reaction-submit-blacklist');
        mockGetStoredOptions.mockResolvedValue({
            atlasDomain: 'https://atlas.test',
            apiToken: 'test-api-token',
            siteCustomizations: [],
        });
    });

    it('submits blacklist actions without collecting download cookies', async () => {
        vi.stubGlobal('fetch', vi.fn());
        const runtimeSendMessage = vi.fn((payload: unknown, callback: (response: unknown) => void) => {
            const typed = payload as { type?: string };
            if (typed.type !== 'ATLAS_SUBMIT_REACTION') {
                callback(null);
                return;
            }

            callback({
                ok: true,
                status: 200,
                payload: {
                    reaction: null,
                    exists: true,
                    blacklisted_at: '2026-05-10T12:00:00Z',
                    file: { id: 42 },
                    download: {
                        requested: false,
                        transfer_id: null,
                        status: null,
                        progress_percent: null,
                    },
                },
            });
        });
        vi.stubGlobal('chrome', {
            runtime: {
                lastError: null,
                sendMessage: runtimeSendMessage,
            },
        });

        const { submitBadgeReaction } = await import('./reaction-submit');
        const image = document.createElement('img');
        image.src = 'https://images.example.com/direct-image-1.jpg';

        const result = await submitBadgeReaction(image, 'blacklist');

        expect(result).toMatchObject({
            ok: true,
            reaction: null,
            blacklistedAt: '2026-05-10T12:00:00Z',
            downloadRequested: false,
        });
        expect(runtimeSendMessage).toHaveBeenCalledTimes(1);

        const submitCall = runtimeSendMessage.mock.calls[0] as [Record<string, unknown>, (response: unknown) => void];
        expect(submitCall[0].endpoint).toBe('https://atlas.test/api/extension/reactions');
        expect(submitCall[0].body).toMatchObject({
            type: 'blacklist',
            download_behavior: 'skip',
            url: 'https://images.example.com/direct-image-1.jpg',
            cookies: null,
        });
    });
});
