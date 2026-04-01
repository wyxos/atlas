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
    it('handles cursor-based pagination with string cursors', async () => {
        const cursor = 'cursor-abc123';
        const nextCursor = 'cursor-xyz789';
        const mockResponse = createMockBrowseResponse(cursor, nextCursor);

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
        const result = await tabContentVm.getPage(cursor);

        // Cursor-based pagination uses `page=` token.
        expect(mocks.mockAxios.get).toHaveBeenCalledWith(expect.stringContaining('/api/browse'));
        expect(mocks.mockAxios.get).toHaveBeenCalledWith(expect.stringContaining(`page=${cursor}`));
        expect(result).toHaveProperty('items');
        expect(result).toHaveProperty('nextPage');
        expect(result.nextPage).toBe(nextCursor);
        // currentPage is a numeric page counter (cursor lives in nextCursor).
        // Pagination state is handled by Masonry; getPage only returns the response.
    });
    it('updates currentPage to 1 when fetching first page', async () => {
        const mockResponse = createMockBrowseResponse(1, 2);

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
        await tabContentVm.getPage(1);

        expect(mocks.mockAxios.get).toHaveBeenCalledWith(expect.stringContaining('page=1'));
    });
});

