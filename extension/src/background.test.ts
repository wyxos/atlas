import { beforeEach, describe, expect, it, vi } from 'vitest';

type BrowserTab = {
    id?: number;
    url?: string;
    active?: boolean;
    discarded?: boolean;
};

type RuntimeMessageListener = (
    message: unknown,
    sender: { tab?: { id?: number } },
    sendResponse: (response?: unknown) => void,
) => boolean | void;

function createChromeMock(initialTabs: BrowserTab[]) {
    const tabs = [...initialTabs];
    let runtimeMessageListener: RuntimeMessageListener | null = null;

    const chromeMock = {
        runtime: {
            lastError: null,
            onInstalled: {
                addListener: vi.fn(),
            },
            onMessage: {
                addListener: vi.fn((listener: RuntimeMessageListener) => {
                    runtimeMessageListener = listener;
                }),
            },
        },
        tabs: {
            query: vi.fn((_: unknown, callback: (items: BrowserTab[]) => void) => {
                callback([...tabs]);
            }),
            sendMessage: vi.fn(),
            remove: vi.fn(),
            discard: vi.fn(),
            onCreated: {
                addListener: vi.fn(),
            },
            onRemoved: {
                addListener: vi.fn(),
            },
            onUpdated: {
                addListener: vi.fn(),
            },
        },
    };

    return {
        chromeMock,
        getRuntimeMessageListener: () => runtimeMessageListener,
    };
}

function sendRuntimeMessage(
    listener: RuntimeMessageListener,
    message: unknown,
    sender: { tab?: { id?: number } } = {},
): Promise<unknown> {
    return new Promise((resolve) => {
        listener(message, sender, resolve);
    });
}

describe('background', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
    });

    it('returns the current comparable open-tab snapshot, preserving hashes and excluding plain roots', async () => {
        const { chromeMock, getRuntimeMessageListener } = createChromeMock([
            { id: 1, url: 'https://example.com/post#image-1' },
            { id: 2, url: 'https://example.com/post#image-1' },
            { id: 3, url: 'https://example.com/post#image-2' },
            { id: 4, url: 'https://example.com/' },
            { id: 5, url: 'https://example.com/?view=full' },
        ]);
        vi.stubGlobal('chrome', chromeMock);

        await import('./background');

        const listener = getRuntimeMessageListener();
        expect(listener).toBeTypeOf('function');

        const response = await sendRuntimeMessage(listener!, { type: 'ATLAS_GET_OPEN_COMPARABLE_URLS' }) as {
            urls?: unknown;
        };

        expect(response.urls).toEqual([
            'https://example.com/post#image-1',
            'https://example.com/post#image-1',
            'https://example.com/post#image-2',
            'https://example.com/?view=full',
        ]);
    });

    it('checks duplicate tabs with sender exclusion and hash-sensitive matching', async () => {
        const { chromeMock, getRuntimeMessageListener } = createChromeMock([
            { id: 1, url: 'https://example.com/post#image-1' },
            { id: 2, url: 'https://example.com/post#image-1' },
            { id: 3, url: 'https://example.com/post#image-2' },
        ]);
        vi.stubGlobal('chrome', chromeMock);

        await import('./background');

        const listener = getRuntimeMessageListener();
        expect(listener).toBeTypeOf('function');

        const duplicateResponse = await sendRuntimeMessage(
            listener!,
            { type: 'ATLAS_IS_URL_OPEN', url: 'https://example.com/post#image-1' },
            { tab: { id: 1 } },
        ) as { isOpenInAnotherTab?: unknown };
        expect(duplicateResponse.isOpenInAnotherTab).toBe(true);

        const uniqueHashResponse = await sendRuntimeMessage(
            listener!,
            { type: 'ATLAS_IS_URL_OPEN', url: 'https://example.com/post#image-2' },
            { tab: { id: 3 } },
        ) as { isOpenInAnotherTab?: unknown };
        expect(uniqueHashResponse.isOpenInAnotherTab).toBe(false);
    });
});
