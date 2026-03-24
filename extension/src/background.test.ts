import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockConnectBackgroundReverb = vi.fn();

vi.mock('./background-reverb-runtime', () => ({
    connectBackgroundReverb: mockConnectBackgroundReverb,
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

type TabCreatedListener = (tab: BrowserTab) => void;
type TabRemovedListener = (tabId: number) => void;
type TabUpdatedListener = (tabId: number, changeInfo: { url?: string; status?: string }, tab: BrowserTab) => void;

function createChromeMock(initialTabs: BrowserTab[]) {
    const tabs = [...initialTabs];
    let runtimeMessageListener: RuntimeMessageListener | null = null;
    let tabCreatedListener: TabCreatedListener | null = null;
    let tabRemovedListener: TabRemovedListener | null = null;
    let tabUpdatedListener: TabUpdatedListener | null = null;

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
                addListener: vi.fn((listener: TabCreatedListener) => {
                    tabCreatedListener = listener;
                }),
            },
            onRemoved: {
                addListener: vi.fn((listener: TabRemovedListener) => {
                    tabRemovedListener = listener;
                }),
            },
            onUpdated: {
                addListener: vi.fn((listener: TabUpdatedListener) => {
                    tabUpdatedListener = listener;
                }),
            },
        },
    };

    return {
        chromeMock,
        getRuntimeMessageListener: () => runtimeMessageListener,
        triggerTabCreated: (tab: BrowserTab) => {
            if (typeof tab.id === 'number') {
                tabs.push({ ...tab });
            }

            tabCreatedListener?.(tab);
        },
        triggerTabRemoved: (tabId: number) => {
            const index = tabs.findIndex((tab) => tab.id === tabId);
            if (index >= 0) {
                tabs.splice(index, 1);
            }

            tabRemovedListener?.(tabId);
        },
        triggerTabUpdated: (tabId: number, changeInfo: { url?: string; status?: string }, nextTab: BrowserTab) => {
            const index = tabs.findIndex((tab) => tab.id === tabId);
            if (index >= 0) {
                tabs[index] = { ...tabs[index], ...nextTab };
            }

            tabUpdatedListener?.(tabId, changeInfo, nextTab);
        },
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
        vi.useRealTimers();
    });

    it('returns cached comparable open-tab urls and counts, preserving hashes and excluding plain roots', async () => {
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
        expect(chromeMock.tabs.query).toHaveBeenCalledTimes(1);

        const response = await sendRuntimeMessage(listener!, { type: 'ATLAS_GET_OPEN_COMPARABLE_URLS' }) as {
            urls?: unknown;
        };

        expect(response.urls).toEqual([
            'https://example.com/post#image-1',
            'https://example.com/post#image-1',
            'https://example.com/post#image-2',
            'https://example.com/?view=full',
        ]);
        expect(chromeMock.tabs.query).toHaveBeenCalledTimes(1);

        const countsResponse = await sendRuntimeMessage(listener!, { type: 'ATLAS_GET_OPEN_COMPARABLE_URL_COUNTS' }) as {
            counts?: unknown;
        };

        expect(countsResponse.counts).toEqual({
            'https://example.com/post#image-1': 2,
            'https://example.com/post#image-2': 1,
            'https://example.com/?view=full': 1,
        });
        expect(chromeMock.tabs.query).toHaveBeenCalledTimes(1);
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
        expect(chromeMock.tabs.query).toHaveBeenCalledTimes(1);

        const uniqueHashResponse = await sendRuntimeMessage(
            listener!,
            { type: 'ATLAS_IS_URL_OPEN', url: 'https://example.com/post#image-2' },
            { tab: { id: 3 } },
        ) as { isOpenInAnotherTab?: unknown };
        expect(uniqueHashResponse.isOpenInAnotherTab).toBe(false);
        expect(chromeMock.tabs.query).toHaveBeenCalledTimes(1);
    });

    it('returns similar-domain and total tab counts for the requesting tab', async () => {
        const { chromeMock, getRuntimeMessageListener } = createChromeMock([
            { id: 1, url: 'https://www.civitai.com/models/1' },
            { id: 2, url: 'https://images.civitai.com/image/2' },
            { id: 3, url: 'https://example.com/post' },
        ]);
        vi.stubGlobal('chrome', chromeMock);

        await import('./background');

        const listener = getRuntimeMessageListener();
        expect(listener).toBeTypeOf('function');

        const response = await sendRuntimeMessage(listener!, { type: 'ATLAS_GET_TAB_COUNT' }, { tab: { id: 1 } }) as {
            count?: unknown;
            similarDomainCount?: unknown;
        };

        expect(response).toEqual({
            count: 3,
            similarDomainCount: 2,
        });
    });

    it('broadcasts tab counts using precomputed domain groups', async () => {
        const { chromeMock, getRuntimeMessageListener, triggerTabCreated } = createChromeMock([
            { id: 1, url: 'https://www.civitai.com/models/1' },
            { id: 2, url: 'https://images.civitai.com/image/2' },
            { id: 3, url: 'https://example.com/post' },
        ]);
        vi.stubGlobal('chrome', chromeMock);

        await import('./background');

        const listener = getRuntimeMessageListener();
        expect(listener).toBeTypeOf('function');
        expect(chromeMock.tabs.query).toHaveBeenCalledTimes(1);

        triggerTabCreated({ id: 4, url: 'https://images.civitai.com/image/4' });

        const countMessages = chromeMock.tabs.sendMessage.mock.calls.filter((call) => {
            const payload = call[1] as { type?: string } | undefined;
            return payload?.type === 'ATLAS_TAB_COUNT_CHANGED';
        });

        expect(countMessages).toEqual([
            [
                1,
                {
                    type: 'ATLAS_TAB_COUNT_CHANGED',
                    count: 4,
                    similarDomainCount: 3,
                },
                expect.any(Function),
            ],
            [
                2,
                {
                    type: 'ATLAS_TAB_COUNT_CHANGED',
                    count: 4,
                    similarDomainCount: 3,
                },
                expect.any(Function),
            ],
            [
                3,
                {
                    type: 'ATLAS_TAB_COUNT_CHANGED',
                    count: 4,
                    similarDomainCount: 1,
                },
                expect.any(Function),
            ],
            [
                4,
                {
                    type: 'ATLAS_TAB_COUNT_CHANGED',
                    count: 4,
                    similarDomainCount: 3,
                },
                expect.any(Function),
            ],
        ]);
    });

    it('proxies allowed Atlas API requests through the background worker', async () => {
        const { chromeMock, getRuntimeMessageListener } = createChromeMock([]);
        const fetchMock = vi.fn().mockResolvedValue(
            new Response(JSON.stringify({
                reverb: {
                    enabled: true,
                    key: 'atlas-key',
                    host: 'atlas.wyxos.com',
                    port: 443,
                    scheme: 'https',
                    channel: 'private-extension-downloads.test-hash',
                },
            }), { status: 200 }),
        );
        vi.stubGlobal('chrome', chromeMock);
        vi.stubGlobal('fetch', fetchMock);

        await import('./background');

        const listener = getRuntimeMessageListener();
        expect(listener).toBeTypeOf('function');

        const response = await sendRuntimeMessage(listener!, {
            type: 'ATLAS_API_REQUEST',
            atlasDomain: 'https://atlas.wyxos.com',
            apiToken: 'test-api-token',
            endpoint: 'https://atlas.wyxos.com/api/extension/ping',
            method: 'GET',
        }) as Record<string, unknown>;

        expect(fetchMock).toHaveBeenCalledWith('https://atlas.wyxos.com/api/extension/ping', {
            method: 'GET',
            headers: {
                'X-Atlas-Api-Key': 'test-api-token',
            },
        });
        expect(response).toEqual({
            ok: true,
            status: 200,
            payload: {
                reverb: {
                    enabled: true,
                    key: 'atlas-key',
                    host: 'atlas.wyxos.com',
                    port: 443,
                    scheme: 'https',
                    channel: 'private-extension-downloads.test-hash',
                },
            },
        });
    });

    it('fans out download progress events from a single background reverb connection', async () => {
        const { chromeMock, getRuntimeMessageListener } = createChromeMock([
            { id: 1, url: 'https://example.com/a' },
            { id: 2, url: 'https://example.com/b' },
        ]);
        const disconnect = vi.fn();
        const unsubscribeEvent = vi.fn();
        const unsubscribeState = vi.fn();
        let eventHandler: ((event: 'DownloadTransferCreated' | 'DownloadTransferQueued' | 'DownloadTransferProgressUpdated', payload: Record<string, unknown>) => void) | null = null;

        mockConnectBackgroundReverb.mockResolvedValue({
            kind: 'connected',
            domain: 'https://atlas.wyxos.com',
            endpoint: 'https://atlas.wyxos.com:443',
            client: {
                onEvent: (handler: typeof eventHandler) => {
                    eventHandler = handler;
                    return { unsubscribe: unsubscribeEvent };
                },
                onConnectionState: () => ({ unsubscribe: unsubscribeState }),
                onConnectionError: () => ({ unsubscribe: vi.fn() }),
                getConnectionState: () => 'connected',
                getLastConnectionError: () => null,
                disconnect,
            },
        });
        vi.stubGlobal('chrome', chromeMock);

        await import('./background');

        const listener = getRuntimeMessageListener();
        expect(listener).toBeTypeOf('function');

        expect(await sendRuntimeMessage(listener!, { type: 'ATLAS_SUBSCRIBE_DOWNLOAD_PROGRESS' }, { tab: { id: 1 } })).toEqual({ ok: true });
        expect(await sendRuntimeMessage(listener!, { type: 'ATLAS_SUBSCRIBE_DOWNLOAD_PROGRESS' }, { tab: { id: 2 } })).toEqual({ ok: true });

        await vi.waitFor(() => {
            expect(mockConnectBackgroundReverb).toHaveBeenCalledTimes(1);
        });
        expect(eventHandler).not.toBeNull();

        eventHandler?.('DownloadTransferQueued', {
            file_id: 12,
            downloadTransferId: 25,
            original: 'https://cdn.example.com/video.mp4#frag',
            referrer_url: 'https://example.com/page#top',
            status: 'queued',
            percent: 10,
            reaction_type: 'funny',
        });

        await vi.waitFor(() => {
            expect(chromeMock.tabs.sendMessage).toHaveBeenCalledTimes(2);
        });

        expect(chromeMock.tabs.sendMessage).toHaveBeenNthCalledWith(
            1,
            1,
            {
                type: 'ATLAS_DOWNLOAD_PROGRESS_EVENT',
                event: {
                    event: 'DownloadTransferQueued',
                    fileId: 12,
                    transferId: 25,
                    sourceUrl: 'https://cdn.example.com/video.mp4#frag',
                    referrerUrl: 'https://example.com/page#top',
                    status: 'queued',
                    percent: 10,
                    reaction: 'funny',
                    reactedAt: undefined,
                    downloadedAt: undefined,
                    blacklistedAt: undefined,
                    payload: {
                        file_id: 12,
                        downloadTransferId: 25,
                        original: 'https://cdn.example.com/video.mp4#frag',
                        referrer_url: 'https://example.com/page#top',
                        status: 'queued',
                        percent: 10,
                        reaction_type: 'funny',
                    },
                },
            },
            expect.any(Function),
        );
        expect(chromeMock.tabs.sendMessage).toHaveBeenNthCalledWith(
            2,
            2,
            {
                type: 'ATLAS_DOWNLOAD_PROGRESS_EVENT',
                event: {
                    event: 'DownloadTransferQueued',
                    fileId: 12,
                    transferId: 25,
                    sourceUrl: 'https://cdn.example.com/video.mp4#frag',
                    referrerUrl: 'https://example.com/page#top',
                    status: 'queued',
                    percent: 10,
                    reaction: 'funny',
                    reactedAt: undefined,
                    downloadedAt: undefined,
                    blacklistedAt: undefined,
                    payload: {
                        file_id: 12,
                        downloadTransferId: 25,
                        original: 'https://cdn.example.com/video.mp4#frag',
                        referrer_url: 'https://example.com/page#top',
                        status: 'queued',
                        percent: 10,
                        reaction_type: 'funny',
                    },
                },
            },
            expect.any(Function),
        );

        expect(await sendRuntimeMessage(listener!, { type: 'ATLAS_UNSUBSCRIBE_DOWNLOAD_PROGRESS' }, { tab: { id: 1 } })).toEqual({ ok: true });
        expect(disconnect).not.toHaveBeenCalled();

        expect(await sendRuntimeMessage(listener!, { type: 'ATLAS_UNSUBSCRIBE_DOWNLOAD_PROGRESS' }, { tab: { id: 2 } })).toEqual({ ok: true });
        expect(unsubscribeEvent).toHaveBeenCalledTimes(1);
        expect(unsubscribeState).toHaveBeenCalledTimes(1);
        expect(disconnect).toHaveBeenCalledTimes(1);
    });

    it('keeps download progress subscribers when the tab does not return a response payload', async () => {
        const { chromeMock, getRuntimeMessageListener } = createChromeMock([
            { id: 1, url: 'https://example.com/a' },
        ]);
        const disconnect = vi.fn();
        let eventHandler: ((event: 'DownloadTransferCreated' | 'DownloadTransferQueued' | 'DownloadTransferProgressUpdated', payload: Record<string, unknown>) => void) | null = null;

        chromeMock.tabs.sendMessage = vi.fn((_: number, __: unknown, callback?: () => void) => {
            chromeMock.runtime.lastError = {
                message: 'The message port closed before a response was received.',
            };
            callback?.();
            chromeMock.runtime.lastError = null;
        });

        mockConnectBackgroundReverb.mockResolvedValue({
            kind: 'connected',
            domain: 'https://atlas.wyxos.com',
            endpoint: 'https://atlas.wyxos.com:443',
            client: {
                onEvent: (handler: typeof eventHandler) => {
                    eventHandler = handler;
                    return { unsubscribe: vi.fn() };
                },
                onConnectionState: () => ({ unsubscribe: vi.fn() }),
                onConnectionError: () => ({ unsubscribe: vi.fn() }),
                getConnectionState: () => 'connected',
                getLastConnectionError: () => null,
                disconnect,
            },
        });
        vi.stubGlobal('chrome', chromeMock);

        await import('./background');

        const listener = getRuntimeMessageListener();
        expect(listener).toBeTypeOf('function');

        expect(await sendRuntimeMessage(listener!, { type: 'ATLAS_SUBSCRIBE_DOWNLOAD_PROGRESS' }, { tab: { id: 1 } })).toEqual({ ok: true });

        await vi.waitFor(() => {
            expect(mockConnectBackgroundReverb).toHaveBeenCalledTimes(1);
        });

        eventHandler?.('DownloadTransferQueued', { id: 25, file_id: 12, status: 'queued', percent: 10 });
        eventHandler?.('DownloadTransferProgressUpdated', { id: 25, file_id: 12, status: 'downloading', percent: 55 });

        await vi.waitFor(() => {
            expect(chromeMock.tabs.sendMessage).toHaveBeenCalledTimes(2);
        });

        expect(disconnect).not.toHaveBeenCalled();
    });

    it('drops download progress subscribers only when the receiving tab is gone', async () => {
        const { chromeMock, getRuntimeMessageListener } = createChromeMock([
            { id: 1, url: 'https://example.com/a' },
        ]);
        let eventHandler: ((event: 'DownloadTransferCreated' | 'DownloadTransferQueued' | 'DownloadTransferProgressUpdated', payload: Record<string, unknown>) => void) | null = null;

        chromeMock.tabs.sendMessage = vi.fn((_: number, __: unknown, callback?: () => void) => {
            chromeMock.runtime.lastError = {
                message: 'Could not establish connection. Receiving end does not exist.',
            };
            callback?.();
            chromeMock.runtime.lastError = null;
        });

        mockConnectBackgroundReverb.mockResolvedValue({
            kind: 'connected',
            domain: 'https://atlas.wyxos.com',
            endpoint: 'https://atlas.wyxos.com:443',
            client: {
                onEvent: (handler: typeof eventHandler) => {
                    eventHandler = handler;
                    return { unsubscribe: vi.fn() };
                },
                onConnectionState: () => ({ unsubscribe: vi.fn() }),
                onConnectionError: () => ({ unsubscribe: vi.fn() }),
                getConnectionState: () => 'connected',
                getLastConnectionError: () => null,
                disconnect: vi.fn(),
            },
        });
        vi.stubGlobal('chrome', chromeMock);

        await import('./background');

        const listener = getRuntimeMessageListener();
        expect(listener).toBeTypeOf('function');

        expect(await sendRuntimeMessage(listener!, { type: 'ATLAS_SUBSCRIBE_DOWNLOAD_PROGRESS' }, { tab: { id: 1 } })).toEqual({ ok: true });

        await vi.waitFor(() => {
            expect(mockConnectBackgroundReverb).toHaveBeenCalledTimes(1);
        });

        eventHandler?.('DownloadTransferQueued', { id: 25, file_id: 12, status: 'queued', percent: 10 });
        eventHandler?.('DownloadTransferProgressUpdated', { id: 25, file_id: 12, status: 'downloading', percent: 55 });

        await vi.waitFor(() => {
            expect(chromeMock.tabs.sendMessage).toHaveBeenCalledTimes(1);
        });

    });
});
