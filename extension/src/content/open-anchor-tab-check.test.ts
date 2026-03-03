import { beforeEach, describe, expect, it, vi } from 'vitest';

describe('isUrlOpenInAnotherTab', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
    });

    it('excludes plain root urls from opened-tab outline checks', async () => {
        const sendMessage = vi.fn((_: unknown, callback: (response: unknown) => void) => {
            callback({ isOpenInAnotherTab: true });
        });
        vi.stubGlobal('chrome', {
            runtime: {
                sendMessage,
            },
        });

        const { isUrlOpenInAnotherTab } = await import('./open-anchor-tab-check');
        const result = await isUrlOpenInAnotherTab('https://youtube.com/');

        expect(result).toBe(false);
        expect(sendMessage).not.toHaveBeenCalled();
    });

    it('allows root urls with query/hash for opened-tab checks', async () => {
        const sendMessage = vi.fn((_: unknown, callback: (response: unknown) => void) => {
            callback({ isOpenInAnotherTab: true });
        });
        vi.stubGlobal('chrome', {
            runtime: {
                sendMessage,
            },
        });

        const { isUrlOpenInAnotherTab } = await import('./open-anchor-tab-check');
        const queryResult = await isUrlOpenInAnotherTab('https://youtube.com/?v=abc123');
        const hashResult = await isUrlOpenInAnotherTab('https://youtube.com/#watch');

        expect(queryResult).toBe(true);
        expect(hashResult).toBe(true);
        expect(sendMessage).toHaveBeenCalledTimes(2);
    });

    it('returns count for comparable url tab lookup', async () => {
        const sendMessage = vi.fn((message: unknown, callback: (response: unknown) => void) => {
            if (typeof message === 'object' && message !== null && (message as { type?: unknown }).type === 'ATLAS_GET_URL_OPEN_COUNT') {
                callback({ count: 3 });
                return;
            }

            callback({ isOpenInAnotherTab: false });
        });
        vi.stubGlobal('chrome', {
            runtime: {
                sendMessage,
            },
        });

        const { getOpenTabCountForUrl } = await import('./open-anchor-tab-check');
        const result = await getOpenTabCountForUrl('https://example.com/post#comments');

        expect(result).toBe(3);
    });

    it('invalidates cached tab count values', async () => {
        let countResponse = 2;
        const sendMessage = vi.fn((message: unknown, callback: (response: unknown) => void) => {
            if (typeof message === 'object' && message !== null && (message as { type?: unknown }).type === 'ATLAS_GET_URL_OPEN_COUNT') {
                callback({ count: countResponse });
                return;
            }

            callback({ isOpenInAnotherTab: false });
        });
        vi.stubGlobal('chrome', {
            runtime: {
                sendMessage,
            },
        });

        const { getOpenTabCountForUrl, invalidateOpenTabCheckCache } = await import('./open-anchor-tab-check');
        const url = 'https://example.com/post';

        const first = await getOpenTabCountForUrl(url);
        countResponse = 4;
        const cached = await getOpenTabCountForUrl(url);

        invalidateOpenTabCheckCache([url]);
        const refreshed = await getOpenTabCountForUrl(url);

        expect(first).toBe(2);
        expect(cached).toBe(2);
        expect(refreshed).toBe(4);
    });
});
