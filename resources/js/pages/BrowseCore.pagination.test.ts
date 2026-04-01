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
    it('handles API errors gracefully', async () => {
        const networkError = new Error('Network error');

        mocks.mockAxios.get.mockImplementation((url: string) => {
            const tabShowMatch = url.match(/\/api\/tabs\/(\d+)(?:\?|$)/);
            if (tabShowMatch) {
                const id = Number(tabShowMatch[1]);
                return Promise.resolve({
                    data: {
                        tab: {
                            id,
                            label: `Browse ${id}`,
                            params: {},
                            feed: 'online',
                        },
                    },
                });
            }
            if (url.includes(tabIndex.definition.url)) {
                return Promise.resolve({ data: [] });
            }
            if (url.includes(browseIndex.definition.url)) {
                return Promise.reject(networkError);
            }
            return Promise.resolve({ data: { items: [], nextPage: null } });
        });

        const router = await createTestRouter();
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();

        mocks.mockAxios.post.mockResolvedValue({
            data: {
                id: 1,
                label: 'Browse 1',
                params: {},
                position: 0,
                is_active: false,
            },
        });

        const vm = wrapper.vm as any;
        await vm.createTab();
        const activeTab = vm.getActiveTab();
        if (activeTab) {
            activeTab.params.service = 'civit-ai-images';
        }
        await waitForStable(wrapper);

        const tabContentVm = await waitForTabContent(wrapper);
        if (!tabContentVm) {
            return;
        }

        tabContentVm.items = [];
        const getNextPage = tabContentVm.getPage;

        await expect(getNextPage(1)).resolves.toEqual({ items: [], nextPage: null });
        expect(console.error).toHaveBeenCalled();
    });
    it('returns correct structure from getPage with null nextPage', async () => {
        const mockResponse = createMockBrowseResponse(100, null);

        mocks.mockAxios.get.mockImplementation((url: string) => {
            const tabShowMatch = url.match(/\/api\/tabs\/(\d+)(?:\?|$)/);
            if (tabShowMatch) {
                const id = Number(tabShowMatch[1]);
                return Promise.resolve({
                    data: {
                        tab: {
                            id,
                            label: `Browse ${id}`,
                            params: {},
                            feed: 'online',
                        },
                    },
                });
            }
            if (url.includes(tabIndex.definition.url)) {
                return Promise.resolve({ data: [] });
            }
            if (url.includes(browseIndex.definition.url)) {
                return Promise.resolve({ data: mockResponse });
            }
            return Promise.resolve({ data: { items: [], nextPage: null } });
        });

        const router = await createTestRouter();
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();

        mocks.mockAxios.post.mockResolvedValue({
            data: {
                id: 1,
                label: 'Browse 1',
                params: {},
                position: 0,
                is_active: false,
            },
        });

        const vm = wrapper.vm as any;
        await vm.createTab();
        const activeTab = vm.getActiveTab();
        if (activeTab) {
            activeTab.params.service = 'civit-ai-images';
        }
        await waitForStable(wrapper);

        const tabContentVm = await waitForTabContent(wrapper);
        if (!tabContentVm) {
            return;
        }

        tabContentVm.items = [];
        const result = await tabContentVm.getPage(100);

        expect(result).toHaveProperty('items');
        expect(result).toHaveProperty('nextPage');
        expect(result.items).toBeInstanceOf(Array);
        expect(result.items.length).toBe(40);
        expect(result.nextPage).toBeNull();
    });
});

