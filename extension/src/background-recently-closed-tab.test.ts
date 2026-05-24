import { beforeEach, describe, expect, it, vi } from 'vitest';

type BrowserTab = {
    id?: number;
    url?: string;
};

type RuntimeMessageListener = (
    message: unknown,
    sender: { tab?: { id?: number; url?: string } },
    sendResponse: (response?: unknown) => void,
) => boolean | void;

function createChromeMock(initialTabs: BrowserTab[]) {
    const tabs = [...initialTabs];
    let runtimeMessageListener: RuntimeMessageListener | null = null;
    let tabRemovedListener: ((tabId: number) => void) | null = null;

    return {
        chromeMock: {
            runtime: {
                lastError: null,
                onInstalled: { addListener: vi.fn() },
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
                onCreated: { addListener: vi.fn() },
                onRemoved: {
                    addListener: vi.fn((listener: (tabId: number) => void) => {
                        tabRemovedListener = listener;
                    }),
                },
                onUpdated: { addListener: vi.fn() },
            },
        },
        getRuntimeMessageListener: () => runtimeMessageListener,
        triggerTabRemoved: (tabId: number) => {
            const index = tabs.findIndex((tab) => tab.id === tabId);
            if (index >= 0) {
                tabs.splice(index, 1);
            }

            tabRemovedListener?.(tabId);
        },
    };
}

function sendRuntimeMessage(listener: RuntimeMessageListener, message: unknown): Promise<unknown> {
    return new Promise((resolve) => {
        listener(message, {}, resolve);
    });
}

describe('background recently closed tab checks', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
    });

    it('forces one badge check when a recently closed page is restored', async () => {
        const { chromeMock, getRuntimeMessageListener, triggerTabRemoved } = createChromeMock([
            { id: 1, url: 'https://example.com/post#image-1' },
        ]);
        vi.stubGlobal('chrome', chromeMock);

        await import('./background');

        const listener = getRuntimeMessageListener();
        expect(listener).toBeTypeOf('function');

        triggerTabRemoved(1);

        await expect(sendRuntimeMessage(listener!, {
            type: 'ATLAS_SHOULD_FORCE_BADGE_CHECK_ON_PAGE',
            url: 'https://example.com/post#image-1',
        })).resolves.toEqual({ shouldForce: true });

        await expect(sendRuntimeMessage(listener!, {
            type: 'ATLAS_SHOULD_FORCE_BADGE_CHECK_ON_PAGE',
            url: 'https://example.com/post#image-1',
        })).resolves.toEqual({ shouldForce: false });
    });
});
