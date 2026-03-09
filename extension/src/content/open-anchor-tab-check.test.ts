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

    it('preserves hashes when checking whether a url is open in another tab', async () => {
        const sendMessage = vi.fn((payload: unknown, callback: (response: unknown) => void) => {
            callback({ isOpenInAnotherTab: true });
        });
        vi.stubGlobal('chrome', {
            runtime: {
                sendMessage,
            },
        });

        const { isUrlOpenInAnotherTab } = await import('./open-anchor-tab-check');
        const result = await isUrlOpenInAnotherTab('https://youtube.com/watch?v=abc123#image-4');

        expect(result).toBe(true);
        expect(sendMessage).toHaveBeenCalledWith(
            {
                type: 'ATLAS_IS_URL_OPEN',
                url: 'https://youtube.com/watch?v=abc123#image-4',
            },
            expect.any(Function),
        );
    });
});
