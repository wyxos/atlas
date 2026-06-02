import { beforeEach, describe, expect, it, vi } from 'vitest';

function createChromeMock() {
    return {
        runtime: {
            lastError: null as { message?: string } | null,
        },
        tabs: {
            create: vi.fn((_: { url: string; active?: boolean }, callback?: () => void) => {
                callback?.();
            }),
        },
    };
}

describe('handleOpenSourceTabsRuntimeMessage', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
    });

    it('opens every unique valid source URL as an inactive browser tab', async () => {
        const chromeMock = createChromeMock();
        vi.stubGlobal('chrome', chromeMock);

        const { handleOpenSourceTabsRuntimeMessage } = await import('./background-source-tabs');

        const response = await new Promise((resolve) => {
            const handled = handleOpenSourceTabsRuntimeMessage({
                type: 'ATLAS_OPEN_SOURCE_TABS',
                urls: [
                    ' https://www.deviantart.com/artist/art/first#comments ',
                    'https://www.deviantart.com/artist/art/first#other',
                    'https://www.deviantart.com/artist/art/second',
                    'javascript:alert(1)',
                ],
            }, resolve);

            expect(handled).toBe(true);
        });

        expect(chromeMock.tabs.create).toHaveBeenCalledTimes(2);
        expect(chromeMock.tabs.create).toHaveBeenNthCalledWith(
            1,
            { url: 'https://www.deviantart.com/artist/art/first', active: false },
            expect.any(Function),
        );
        expect(chromeMock.tabs.create).toHaveBeenNthCalledWith(
            2,
            { url: 'https://www.deviantart.com/artist/art/second', active: false },
            expect.any(Function),
        );
        expect(response).toEqual({
            ok: true,
            openedCount: 2,
            failedCount: 0,
        });
    });

    it('does not handle unrelated runtime messages', async () => {
        const chromeMock = createChromeMock();
        vi.stubGlobal('chrome', chromeMock);

        const { handleOpenSourceTabsRuntimeMessage } = await import('./background-source-tabs');

        const sendResponse = vi.fn();
        const handled = handleOpenSourceTabsRuntimeMessage({ type: 'ATLAS_UNKNOWN' }, sendResponse);

        expect(handled).toBe(false);
        expect(sendResponse).not.toHaveBeenCalled();
        expect(chromeMock.tabs.create).not.toHaveBeenCalled();
    });
});
