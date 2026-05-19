import { beforeEach, describe, expect, it, vi } from 'vitest';

type BrowserTab = {
    id?: number;
    url?: string;
};

describe('background referrer reaction sync', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
    });

    it('broadcasts referrer sync only to http tabs with content-script receivers', async () => {
        const message = {
            type: 'ATLAS_REFERRER_REACTION_SYNC' as const,
            phase: 'pending' as const,
            urls: ['https://example.com/post#image-1'],
        };
        const chromeMock = {
            runtime: {
                lastError: null,
            },
            tabs: {
                query: vi.fn((_: unknown, callback: (tabs: BrowserTab[]) => void) => {
                    callback([
                        { id: 1, url: 'https://example.com/post#image-1' },
                        { id: 2, url: 'chrome://extensions/' },
                        { id: 3, url: 'about:blank' },
                        { id: 4, url: 'http://example.test/page' },
                        { id: 5 },
                        { id: 6, url: 'https://example.com/source' },
                    ]);
                }),
                sendMessage: vi.fn((_: number, __: unknown, callback?: () => void) => {
                    callback?.();
                }),
            },
        };
        vi.stubGlobal('chrome', chromeMock);

        const { broadcastReferrerReactionSync } = await import('./background-referrer-reaction-sync');

        broadcastReferrerReactionSync(message, 6);

        expect(chromeMock.tabs.sendMessage).toHaveBeenCalledTimes(2);
        expect(chromeMock.tabs.sendMessage).toHaveBeenNthCalledWith(1, 1, message, expect.any(Function));
        expect(chromeMock.tabs.sendMessage).toHaveBeenNthCalledWith(2, 4, message, expect.any(Function));
    });
});
