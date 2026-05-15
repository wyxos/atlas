import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('./atlas-options', () => ({
    getStoredOptions: vi.fn().mockResolvedValue({
        atlasDomain: 'https://atlas.test',
        apiToken: 'test-api-token',
        siteCustomizations: [],
    }),
}));

vi.mock('./background-cookie-runtime', () => ({
    collectCookiesForUrls: vi.fn(),
}));

vi.mock('./background-referrer-check-cache', () => ({
    primeGlobalReferrerCheckCache: vi.fn(),
    primeGlobalReferrerCheckCacheFromProgressEvent: vi.fn(),
}));

vi.mock('./background-reload-overlay', () => ({
    notifyTabsExtensionReloaded: vi.fn(),
}));

type BrowserTab = {
    id?: number;
    url?: string;
    active?: boolean;
    discarded?: boolean;
};

type RuntimeMessageListener = (
    message: unknown,
    sender: { tab?: { id?: number; url?: string } },
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
            discard: vi.fn((tabId: number, callback?: (tab?: BrowserTab) => void) => {
                const tab = tabs.find((item) => item.id === tabId);
                if (!tab) {
                    callback?.(undefined);
                    return;
                }

                tab.discarded = true;
                callback?.({ ...tab });
            }),
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
    sender: { tab?: { id?: number; url?: string } } = {},
): Promise<unknown> {
    return new Promise((resolve) => {
        listener(message, sender, resolve);
    });
}

async function flushMicrotasks(): Promise<void> {
    await Promise.resolve();
    await Promise.resolve();
}

describe('background inactive tab discard', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
        vi.useRealTimers();
    });

    it('reports discarded, skipped, and failed inactive tab counts', async () => {
        const { chromeMock, getRuntimeMessageListener } = createChromeMock([
            { id: 1, url: 'https://example.com/active', active: true },
            { id: 2, url: 'https://example.com/skipped', discarded: true },
            { id: 3, url: 'https://example.com/discard-me' },
            { id: 4, url: 'https://example.com/fail-me' },
        ]);
        chromeMock.tabs.discard = vi.fn((tabId: number, callback?: (tab?: BrowserTab) => void) => {
            if (tabId === 4) {
                callback?.(undefined);
                return;
            }

            callback?.({ id: tabId, discarded: true });
        });
        vi.stubGlobal('chrome', chromeMock);

        await import('./background');

        const listener = getRuntimeMessageListener();
        expect(listener).toBeTypeOf('function');

        const response = await sendRuntimeMessage(listener!, { type: 'ATLAS_DISCARD_INACTIVE_TABS' });

        expect(response).toEqual({
            ok: true,
            discardedCount: 1,
            failedCount: 1,
            skippedCount: 1,
        });
        expect(chromeMock.tabs.discard).toHaveBeenCalledTimes(2);
    });

    it('paces large tab sets instead of starting all discards at once', async () => {
        vi.useFakeTimers();

        const tabs: BrowserTab[] = [
            { id: 1, url: 'https://example.com/active', active: true },
            ...Array.from({ length: 18 }, (_, index) => ({
                id: index + 2,
                url: `https://example.com/discard-${index}`,
            })),
        ];
        const pendingDiscardCallbacks: Array<{
            tabId: number;
            callback?: (tab?: BrowserTab) => void;
        }> = [];
        const { chromeMock, getRuntimeMessageListener } = createChromeMock(tabs);
        chromeMock.tabs.discard = vi.fn((tabId: number, callback?: (tab?: BrowserTab) => void) => {
            pendingDiscardCallbacks.push({ tabId, callback });
        });
        vi.stubGlobal('chrome', chromeMock);

        await import('./background');

        const listener = getRuntimeMessageListener();
        expect(listener).toBeTypeOf('function');

        const responsePromise = sendRuntimeMessage(listener!, { type: 'ATLAS_DISCARD_INACTIVE_TABS' });
        await flushMicrotasks();

        expect(chromeMock.tabs.discard).toHaveBeenCalledTimes(8);

        pendingDiscardCallbacks.splice(0).forEach(({ tabId, callback }) => {
            callback?.({ id: tabId, discarded: true });
        });
        await flushMicrotasks();
        await vi.advanceTimersByTimeAsync(49);
        expect(chromeMock.tabs.discard).toHaveBeenCalledTimes(8);

        await vi.advanceTimersByTimeAsync(1);
        await flushMicrotasks();
        expect(chromeMock.tabs.discard).toHaveBeenCalledTimes(16);

        pendingDiscardCallbacks.splice(0).forEach(({ tabId, callback }) => {
            callback?.({ id: tabId, discarded: true });
        });
        await vi.advanceTimersByTimeAsync(50);
        await flushMicrotasks();
        expect(chromeMock.tabs.discard).toHaveBeenCalledTimes(18);

        pendingDiscardCallbacks.splice(0).forEach(({ tabId, callback }) => {
            callback?.({ id: tabId, discarded: true });
        });

        await expect(responsePromise).resolves.toEqual({
            ok: true,
            discardedCount: 18,
            failedCount: 0,
            skippedCount: 0,
        });
    });
});
