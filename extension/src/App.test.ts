import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const mockResolveApiConnectionStatus = vi.fn();

vi.mock('./atlas-api', () => ({
    resolveApiConnectionStatus: mockResolveApiConnectionStatus,
}));

type BrowserTab = {
    id?: number;
    url?: string;
    active?: boolean;
};

type ChromeMockOptions = {
    discardResponse?: unknown;
    openOptionsFallback?: boolean;
};

function createChromeMock(tabs: BrowserTab[], options: ChromeMockOptions = {}) {
    const runtime = {
        getManifest: () => ({
            version: '1.2.3',
        }),
        getURL: vi.fn((path: string) => `chrome-extension://atlas/${path}`),
        lastError: null as { message: string } | null,
        openOptionsPage: vi.fn((callback?: () => void) => {
            if (options.openOptionsFallback) {
                runtime.lastError = { message: 'Cannot open options page.' };
            }

            callback?.();
            runtime.lastError = null;
        }),
        sendMessage: vi.fn((message: { type?: string }, callback?: (payload: unknown) => void) => {
            if (message.type === 'ATLAS_DISCARD_INACTIVE_TABS') {
                callback?.(options.discardResponse ?? null);
                return;
            }

            callback?.(null);
        }),
    };

    return {
        runtime,
        tabs: {
            query: vi.fn((queryInfo: Record<string, unknown>, callback: (items: BrowserTab[]) => void) => {
                if (queryInfo.active === true && queryInfo.currentWindow === true) {
                    callback(tabs.filter((tab) => tab.active === true));
                    return;
                }

                callback(tabs);
            }),
            create: vi.fn(),
            onActivated: {
                addListener: vi.fn(),
                removeListener: vi.fn(),
            },
            onCreated: {
                addListener: vi.fn(),
                removeListener: vi.fn(),
            },
            onRemoved: {
                addListener: vi.fn(),
                removeListener: vi.fn(),
            },
            onUpdated: {
                addListener: vi.fn(),
                removeListener: vi.fn(),
            },
        },
    };
}

async function mountApp(tabs: BrowserTab[], options: ChromeMockOptions = {}) {
    const chromeMock = createChromeMock(tabs, options);
    vi.stubGlobal('chrome', chromeMock);
    const component = await import('./App.vue');

    const wrapper = mount(component.default, {
        global: {
            stubs: {
                Badge: {
                    template: '<span><slot /></span>',
                },
            },
        },
    });

    return { chromeMock, wrapper };
}

describe('App', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
        vi.useFakeTimers();

        mockResolveApiConnectionStatus.mockResolvedValue({
            label: 'Ready',
            detail: 'Connected.',
            reverbLabel: 'Connected',
            reverbDetail: 'Listening.',
            reverbEndpoint: 'wss://atlas.test/reverb',
        });
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders similar-domain and total tab counts for the active tab', async () => {
        const { wrapper } = await mountApp([
            { id: 1, url: 'https://www.civitai.com/models/1', active: true },
            { id: 2, url: 'https://images.civitai.com/image/2' },
            { id: 3, url: 'https://example.com/post' },
        ]);

        await vi.runAllTimersAsync();
        await flushPromises();

        expect(wrapper.text()).toContain('Tabs 2/3');
    });

    it('opens the options page from the popup', async () => {
        const { chromeMock, wrapper } = await mountApp([]);

        await vi.runAllTimersAsync();
        await flushPromises();

        const openOptionsButton = wrapper.findAll('button')
            .find((button) => button.text() === 'Open Options');
        expect(openOptionsButton).toBeTruthy();

        await openOptionsButton!.trigger('click');

        expect(chromeMock.runtime.openOptionsPage).toHaveBeenCalledTimes(1);
        expect(chromeMock.tabs.create).not.toHaveBeenCalled();
    });

    it('falls back to opening options in a new tab when the runtime options page fails', async () => {
        const { chromeMock, wrapper } = await mountApp([], { openOptionsFallback: true });

        await vi.runAllTimersAsync();
        await flushPromises();

        const openOptionsButton = wrapper.findAll('button')
            .find((button) => button.text() === 'Open Options');
        expect(openOptionsButton).toBeTruthy();

        await openOptionsButton!.trigger('click');

        expect(chromeMock.runtime.openOptionsPage).toHaveBeenCalledTimes(1);
        expect(chromeMock.tabs.create).toHaveBeenCalledWith({
            url: 'chrome-extension://atlas/options.html',
        });
    });

    it('renders the discard-inactive-tabs result summary from the runtime response', async () => {
        const { chromeMock, wrapper } = await mountApp([], {
            discardResponse: {
                ok: true,
                discardedCount: 2,
                failedCount: 1,
                skippedCount: 3,
            },
        });

        await vi.runAllTimersAsync();
        await flushPromises();

        await wrapper.get('[data-test="discard-inactive-tabs"]').trigger('click');
        await flushPromises();

        expect(chromeMock.runtime.sendMessage).toHaveBeenCalledWith(
            { type: 'ATLAS_DISCARD_INACTIVE_TABS' },
            expect.any(Function),
        );
        expect(wrapper.text()).toContain('Discarded 2 tabs, skipped 3 already discarded, failed 1.');
    });

    it('shows a failure message when discarding inactive tabs does not return a valid response', async () => {
        const { wrapper } = await mountApp([], {
            discardResponse: null,
        });

        await vi.runAllTimersAsync();
        await flushPromises();

        await wrapper.get('[data-test="discard-inactive-tabs"]').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Failed to discard inactive tabs.');
    });
});
