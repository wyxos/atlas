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
    it('handles tab with page parameter in params and loads items lazily', async () => {
        const tabId = 1;
        const pageParam = 'cursor-string-123';

        mocks.mockAxios.get.mockImplementation((url: string) => {
            if (url.includes(tabIndex.definition.url) && !url.match(/\/api\/tabs\/\d+/)) {
                return Promise.resolve({
                    data: [{
                        id: tabId,
                        label: 'Test Tab',
                        params: { service: 'civit-ai-images', page: pageParam },
                        position: 0,
                        is_active: true,
                    }],
                });
            }
            if (url.includes(tabShow.url({ tab: 1 }))) {
                return Promise.resolve({
                    data: {
                        tab: {
                            id: tabId,
                            label: 'Test Tab',
                            params: { service: 'civit-ai-images', page: pageParam },
                            feed: 'online',
                        },
                    },
                });
            }
            return Promise.resolve({ data: { items: [], nextPage: null } });
        });

        const router = await createTestRouter('/browse');

        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await waitForStable(wrapper);

        const vm = wrapper.vm as any;
        expect(vm.activeTabId).toBe(tabId);

        await waitForStable(wrapper);

        const masonry = wrapper.findComponent({ name: 'Masonry' });
        expect(masonry.exists()).toBe(true);
        expect(masonry.props('page')).toBe(pageParam);
        expect(mocks.mockAxios.get).toHaveBeenCalledWith(tabShow.url({ tab: 1 }));
    });
    it('handles tab with page in params correctly and loads items lazily', async () => {
        const tabId = 1;
        const pageValue = 123;

        mocks.mockAxios.get.mockImplementation((url: string) => {
            if (url.includes(tabIndex.definition.url) && !url.match(/\/api\/tabs\/\d+/)) {
                return Promise.resolve({
                    data: [{
                        id: tabId,
                        label: 'Test Tab',
                        params: { service: 'civit-ai-images', page: pageValue },
                        position: 0,
                        is_active: true,
                    }],
                });
            }
            if (url.includes(tabShow.url({ tab: 1 }))) {
                return Promise.resolve({
                    data: {
                        tab: {
                            id: tabId,
                            label: 'Test Tab',
                            params: { service: 'civit-ai-images', page: pageValue },
                            feed: 'online',
                        },
                    },
                });
            }
            return Promise.resolve({ data: { items: [], nextPage: null } });
        });

        const router = await createTestRouter('/browse');

        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await waitForStable(wrapper);

        await waitForStable(wrapper);

        const masonry = wrapper.findComponent({ name: 'Masonry' });
        expect(masonry.exists()).toBe(true);
        expect(masonry.props('page')).toBe(pageValue);
        expect(mocks.mockAxios.get).toHaveBeenCalledWith(tabShow.url({ tab: 1 }));
    });
});

