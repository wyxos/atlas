import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockGetStoredOptions = vi.fn();

vi.mock('../atlas-options', () => ({
    getStoredOptions: mockGetStoredOptions,
}));

describe('submitBadgeReaction referrer cleanup', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
        history.replaceState({}, '', '/extension-test/reaction-submit?id=123&tag=blue+sky');
    });

    it('strips configured referrer query params before submit while keeping the raw page url', async () => {
        const pageUrl = window.location.href;
        const pageHostname = window.location.hostname;
        mockGetStoredOptions.mockResolvedValue({
            atlasDomain: 'https://atlas.test',
            apiToken: 'test-api-token',
            siteCustomizations: [
                {
                    domain: pageHostname,
                    matchRules: [],
                    referrerCleaner: {
                        stripQueryParams: ['tag', 'tags'],
                    },
                    mediaCleaner: {
                        stripQueryParams: [],
                        rewriteRules: [],
                        strategies: [],
                    },
                },
            ],
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
        const video = document.createElement('video');
        video.poster = 'https://cdn.example.com/poster.jpg';

        const result = await submitBadgeReaction(video, 'like');

        expect(result.ok).toBe(true);
        const submitCall = runtimeSendMessage.mock.calls[1] as [Record<string, unknown>, (response: unknown) => void];
        const body = submitCall[0].body as Record<string, unknown>;
        expect(body.referrer_url_hash_aware).toBe(`${window.location.origin}/extension-test/reaction-submit?id=123`);
        expect(body.page_url).toBe(pageUrl);
    });
});
