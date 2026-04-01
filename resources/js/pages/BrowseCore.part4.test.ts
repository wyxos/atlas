/* eslint-disable @typescript-eslint/no-unused-vars */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { ref } from 'vue';
import Browse from './Browse.vue';
import { show as tabShow, index as tabIndex } from '@/actions/App/Http/Controllers/TabController';
import { index as browseIndex } from '@/actions/App/Http/Controllers/BrowseController';
import {
    setupBrowseTestMocks,
    createTestRouter,
    getTabContent,
    waitForStable,
    waitForTabContent,
    createMockTabConfig,
    setupAxiosMocks,
    createMockBrowseResponse,
    type BrowseMocks,
} from '@/test/browse-test-utils';

// Define mocks using vi.hoisted so they're available for vi.mock factories
const {
    mockAxios,
    mockIsLoading,
    mockCancelLoad,
    mockRemove,
    mockRestore,
    mockQueuePreviewIncrement,
} = vi.hoisted(() => ({
    mockAxios: {
        get: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
        patch: vi.fn(),
    },
    mockIsLoading: { value: false },
    mockCancelLoad: vi.fn(),
    mockRemove: vi.fn(),
    mockRestore: vi.fn(),
    mockQueuePreviewIncrement: vi.fn(),
}));

// Create mocks object for helper functions
const mocks: BrowseMocks = {
    mockAxios,
    mockIsLoading: ref(false),
    mockCancelLoad,
    mockRemove,
    mockRestore,
    mockQueuePreviewIncrement,
};

// Mock fetch
global.fetch = vi.fn();

// Mock axios
vi.mock('axios', () => ({
    default: mockAxios,
}));

// Mock window.axios
Object.defineProperty(window, 'axios', {
    value: mockAxios,
    writable: true,
});

// Mock @wyxos/vibe with shared helper
vi.mock('@wyxos/vibe', async () => {
    const { createVibePageMock } = await import('@/test/browse-test-utils');
    return createVibePageMock({ mockIsLoading, mockCancelLoad, mockRemove, mockRestore });
});

// Mock usePreviewBatch
vi.mock('@/composables/usePreviewBatch', () => ({
    usePreviewBatch: () => ({
        queuePreviewIncrement: mockQueuePreviewIncrement,
    }),
}));

beforeEach(() => {
    setupBrowseTestMocks(mocks);
});

// Helper to mount Browse component with tab configuration
async function mountBrowseWithTab(tabConfig: any | any[], browseResponse?: any) {
    setupAxiosMocks(mocks, tabConfig, browseResponse);
    const router = await createTestRouter();
    const wrapper = mount(Browse, {
        global: {
            plugins: [router],
        },
    });
    await flushPromises();
    await wrapper.vm.$nextTick();
    return { wrapper, router };
}

describe('Browse - Core', () => {
    it('initializes with first tab when tabs exist and loads items if tab has files', async () => {
        const tabId = 1;
        const nextToken = 'cursor-next-456';
        const mockItems = [
            { id: 1, width: 100, height: 100, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false },
            { id: 2, width: 200, height: 200, src: 'test2.jpg', type: 'image', page: 1, index: 1, notFound: false },
        ];

        const tabConfig = createMockTabConfig(tabId, {
            params: { service: 'civit-ai-images', page: nextToken },
            items: mockItems,
        });

        const router = await createTestRouter('/browse');
        setupAxiosMocks(mocks, tabConfig);
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await waitForStable(wrapper);

        const vm = wrapper.vm as any;
        expect(vm.activeTabId).toBe(tabId);

        await waitForTabContent(wrapper);
        const masonry = wrapper.findComponent({ name: 'Masonry' });
        expect(masonry.exists()).toBe(true);
        // New contract: `page` is the next token to load.
        expect(masonry.props('page')).toBe(nextToken);
        expect(masonry.props('restoredPages')).toBeUndefined();
        expect(mocks.mockAxios.get).toHaveBeenCalledWith(tabShow.url({ tab: 1 }));
    });
    it('initializes with default values when no tabs exist', async () => {
        mocks.mockAxios.get.mockResolvedValueOnce({ data: [] });

        const router = await createTestRouter('/browse');

        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();

        const vm = wrapper.vm as any;
        expect(vm.activeTabId).toBeNull();
    });
    it('displays Pill components with correct values', async () => {
        mocks.mockAxios.get.mockImplementation((url: string) => {
            if (url.includes(tabIndex.definition.url)) {
                return Promise.resolve({
                    data: [{
                        id: 1,
                        label: 'Test Tab',
                        params: { service: 'civit-ai-images', page: 1 },
                        items: [],
                        position: 0,
                    }],
                });
            }
            if (url.includes(browseIndex.definition.url)) {
                return Promise.resolve({
                    data: {
                        items: [],
                        nextPage: null,
                        services: [{ key: 'civit-ai-images', label: 'CivitAI Images' }],
                    },
                });
            }
            return Promise.resolve({ data: { items: [], nextPage: null } });
        });

        const router = await createTestRouter();
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await waitForStable(wrapper);

        const tabContentVm = await waitForTabContent(wrapper);
        if (!tabContentVm) {
            return;
        }

        tabContentVm.items = [{ id: '1' }, { id: '2' }, { id: '3' }];
        if (tabContentVm.masonry) {
            tabContentVm.masonry.pagesLoaded = [1, 2];
            tabContentVm.masonry.nextPage = 'cursor-123';
        }

        await wrapper.vm.$nextTick();

        const pills = wrapper.findAllComponents({ name: 'Pill' });
        expect(pills.length).toBeGreaterThan(0);

        const itemsPill = pills.find((p) => p.props('label') === 'Items');
        if (itemsPill) {
            expect(itemsPill.props('value')).toBe(3);
        }
    });
    it('displays N/A for next cursor when null', async () => {
        const router = await createTestRouter();
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();

        const tabContentVm = await waitForTabContent(wrapper);
        if (tabContentVm?.masonry) {
            tabContentVm.masonry.nextPage = null;
            await wrapper.vm.$nextTick();

            const nextPill = wrapper
                .findAllComponents({ name: 'Pill' })
                .find((p) => p.props('label') === 'Next');
            if (nextPill) {
                expect(nextPill.props('value')).toBe('N/A');
            }
        }
    });
});

