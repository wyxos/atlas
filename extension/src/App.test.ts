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

function createChromeMock(tabs: BrowserTab[]) {
    return {
        runtime: {
            getManifest: () => ({
                version: '1.2.3',
            }),
            lastError: null,
            openOptionsPage: vi.fn(),
        },
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

async function mountApp(tabs: BrowserTab[]) {
    vi.stubGlobal('chrome', createChromeMock(tabs));
    const component = await import('./App.vue');

    return mount(component.default, {
        global: {
            stubs: {
                Badge: {
                    template: '<span><slot /></span>',
                },
            },
        },
    });
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
        const wrapper = await mountApp([
            { id: 1, url: 'https://www.civitai.com/models/1', active: true },
            { id: 2, url: 'https://images.civitai.com/image/2' },
            { id: 3, url: 'https://example.com/post' },
        ]);

        await vi.runAllTimersAsync();
        await flushPromises();

        expect(wrapper.text()).toContain('Tabs 2/3');
    });
});
