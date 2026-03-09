import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockGetStoredOptions = vi.fn();

vi.mock('../atlas-options', () => ({
    getStoredOptions: mockGetStoredOptions,
}));

describe('submitBadgeReaction keepalive', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
        history.replaceState({}, '', '/extension-test/reaction-submit-keepalive');
    });

    it('disables keepalive for oversized batch fallback payloads', async () => {
        mockGetStoredOptions.mockResolvedValue({
            atlasDomain: 'https://atlas.test',
            apiToken: 'test-api-token',
            matchRules: [],
        });

        const fetchMock = vi.fn().mockResolvedValue(
            new Response(JSON.stringify({
                reaction: 'love',
                exists: true,
                download: {
                    requested: true,
                    transfer_id: null,
                    status: null,
                    progress_percent: null,
                },
            }), { status: 200 }),
        );
        vi.stubGlobal('fetch', fetchMock);
        const runtimeSendMessage = vi.fn((payload: unknown, callback: (response: unknown) => void) => {
            const typed = payload as { type?: string };
            if (typed.type === 'ATLAS_GET_URL_COOKIES') {
                callback({ cookies: [] });
                return;
            }

            callback(undefined);
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

        const longToken = 'a'.repeat(3200);
        const batchItems = Array.from({ length: 30 }, (_, index) => ({
            candidateId: `image-${index + 1}`,
            url: `https://images.example.com/direct-image-${index + 1}.jpg?token=${longToken}${index}`,
            referrerUrlHashAware: `https://www.deviantart.com/artist/art/post-1#image-${index + 1}`,
            pageUrl: 'https://www.deviantart.com/artist/art/post-1',
            tagName: 'img' as const,
        }));

        const result = await submitBadgeReaction(image, 'love', { batchItems });

        expect(result.ok).toBe(true);
        expect(fetchMock).toHaveBeenCalledTimes(1);

        const fetchCall = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(fetchCall[0]).toBe('https://atlas.test/api/extension/reactions/batch');
        expect(fetchCall[1].keepalive).toBe(false);
    });
});
