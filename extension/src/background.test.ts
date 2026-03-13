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
                    channel: 'downloads',
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
                    channel: 'downloads',
                },
            },
        });
    });
});
