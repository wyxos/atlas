import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockCollectCookiesForUrls = vi.fn();
const mockNotifyTabsExtensionReloaded = vi.fn();

vi.mock('./background-cookie-runtime', () => ({
    collectCookiesForUrls: mockCollectCookiesForUrls,
}));

vi.mock('./background-reload-overlay', () => ({
    notifyTabsExtensionReloaded: mockNotifyTabsExtensionReloaded,
}));

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
    let runtimeInstalledListener: ((details: { reason: string }) => void) | null = null;

    const chromeMock = {
        runtime: {
            lastError: null,
            onInstalled: {
                addListener: vi.fn((listener: (details: { reason: string }) => void) => {
                    runtimeInstalledListener = listener;
                }),
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
            remove: vi.fn((tabId: number, callback?: () => void) => {
                const index = tabs.findIndex((tab) => tab.id === tabId);
                if (index >= 0) {
                    tabs.splice(index, 1);
                }

                callback?.();
            }),
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
        getRuntimeInstalledListener: () => runtimeInstalledListener,
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

describe('background runtime message bridge', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
    });

    it('normalizes cookie urls and proxies cookie lookups through the background runtime', async () => {
        const { chromeMock, getRuntimeMessageListener } = createChromeMock([]);
        mockCollectCookiesForUrls.mockResolvedValue([
            {
                name: 'session',
                value: 'alpha',
                domain: 'example.com',
                path: '/',
                secure: true,
                http_only: true,
                host_only: false,
                expires_at: 42,
            },
        ]);
        vi.stubGlobal('chrome', chromeMock);

        await import('./background');

        const listener = getRuntimeMessageListener();
        expect(listener).toBeTypeOf('function');

        const response = await sendRuntimeMessage(listener!, {
            type: 'ATLAS_GET_URL_COOKIES',
            urls: [
                'https://example.com/post#one',
                'https://example.com/post#two',
                'not-a-url',
            ],
        }) as { cookies?: unknown };

        expect(mockCollectCookiesForUrls).toHaveBeenCalledWith(['https://example.com/post']);
        expect(response.cookies).toEqual([
            {
                name: 'session',
                value: 'alpha',
                domain: 'example.com',
                path: '/',
                secure: true,
                http_only: true,
                host_only: false,
                expires_at: 42,
            },
        ]);
    });

    it('closes the sender tab and rejects close requests without a tab id', async () => {
        const { chromeMock, getRuntimeMessageListener } = createChromeMock([
            { id: 9, url: 'https://example.com/post' },
        ]);
        vi.stubGlobal('chrome', chromeMock);

        await import('./background');

        const listener = getRuntimeMessageListener();
        expect(listener).toBeTypeOf('function');

        const success = await sendRuntimeMessage(
            listener!,
            { type: 'ATLAS_CLOSE_CURRENT_TAB' },
            { tab: { id: 9 } },
        );
        expect(success).toEqual({ ok: true });
        expect(chromeMock.tabs.remove).toHaveBeenCalledWith(9, expect.any(Function));

        const missingSender = await sendRuntimeMessage(listener!, { type: 'ATLAS_CLOSE_CURRENT_TAB' });
        expect(missingSender).toEqual({ ok: false });
    });

    it('discards inactive tabs and reports discarded, skipped, and failed counts', async () => {
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

    it('notifies tabs to reload after extension installs and updates', async () => {
        const { chromeMock, getRuntimeInstalledListener } = createChromeMock([]);
        vi.stubGlobal('chrome', chromeMock);

        await import('./background');

        const listener = getRuntimeInstalledListener();
        expect(listener).toBeTypeOf('function');

        listener?.({ reason: 'install' });
        listener?.({ reason: 'update' });
        listener?.({ reason: 'chrome_update' });

        expect(mockNotifyTabsExtensionReloaded).toHaveBeenCalledTimes(2);
    });
});
