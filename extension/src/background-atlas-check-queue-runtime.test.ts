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

    return {
        chromeMock: {
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
        },
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

describe('background atlas check queue runtime bridge', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
    });

    it('routes global badge and referrer queue messages through dedicated background handlers', async () => {
        const { chromeMock, getRuntimeMessageListener } = createChromeMock([]);
        const fetchMock = vi.fn().mockImplementation((endpoint: string, init?: RequestInit) => {
            const body = JSON.parse(String(init?.body ?? '{}')) as {
                items?: Array<{ request_id?: string }>;
            };

            return Promise.resolve(new Response(JSON.stringify({
                matches: (body.items ?? []).map((item) => ({
                    request_id: item.request_id,
                    exists: true,
                    reaction: endpoint.includes('referrer-checks') ? 'funny' : 'love',
                })),
            }), { status: 200 }));
        });
        vi.stubGlobal('chrome', chromeMock);
        vi.stubGlobal('fetch', fetchMock);

        await import('./background');

        const listener = getRuntimeMessageListener();
        expect(listener).toBeTypeOf('function');

        const badgePromise = sendRuntimeMessage(listener!, {
            type: 'ATLAS_QUEUE_BADGE_CHECK',
            atlasDomain: 'https://atlas.wyxos.com',
            apiToken: 'test-api-token',
            normalizedMediaUrl: 'https://cdn.example.com/image.jpg',
        });
        const referrerPromise = sendRuntimeMessage(listener!, {
            type: 'ATLAS_QUEUE_REFERRER_CHECK',
            atlasDomain: 'https://atlas.wyxos.com',
            apiToken: 'test-api-token',
            normalizedReferrerUrl: 'https://example.com/post#one',
        });

        await vi.waitFor(() => {
            expect(fetchMock).toHaveBeenCalledTimes(2);
        });

        expect(await badgePromise).toEqual({
            ok: true,
            status: 200,
            payload: {
                exists: true,
                reaction: 'love',
                reactedAt: null,
                downloadedAt: null,
                blacklistedAt: null,
            },
        });
        expect(await referrerPromise).toEqual({
            ok: true,
            status: 200,
            payload: {
                exists: true,
                reaction: 'funny',
                reactedAt: null,
                downloadedAt: null,
                blacklistedAt: null,
            },
        });
    });
});
