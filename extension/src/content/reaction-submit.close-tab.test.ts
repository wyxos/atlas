import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockGetStoredOptions = vi.fn();

vi.mock('../atlas-options', () => ({
    getStoredOptions: mockGetStoredOptions,
}));

describe('submitBadgeReaction close-tab behavior', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
        history.replaceState({}, '', '/extension-test/reaction-submit-close-tab');
    });

    it('does not enable close-tab for positive reactions without download queueing', async () => {
        mockGetStoredOptions.mockResolvedValue({
            atlasDomain: 'https://atlas.test',
            apiToken: 'test-api-token',
            siteCustomizations: [],
        });

        vi.stubGlobal('fetch', vi.fn());
        const runtimeSendMessage = vi.fn((payload: unknown, callback: (response: unknown) => void) => {
            const typed = payload as { type?: string };
            if (typed.type === 'ATLAS_GET_URL_COOKIES') {
                callback({ cookies: [] });
                return;
            }

            if (typed.type === 'ATLAS_SUBMIT_REACTION') {
                callback({
                    ok: true,
                    status: 200,
                    payload: {
                        reaction: 'like',
                        exists: true,
                        download: {
                            requested: false,
                            transfer_id: null,
                            status: null,
                            progress_percent: null,
                        },
                    },
                });
                return;
            }

            callback(null);
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

        const result = await submitBadgeReaction(image, 'like');

        expect(result.ok).toBe(true);
        expect(result.downloadRequested).toBe(false);
        expect(result.shouldCloseTabAfterQueue).toBe(false);
    });
});
