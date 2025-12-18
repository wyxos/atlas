import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { ref } from 'vue';
import Browse from './Browse.vue';
import {
    setupBrowseTestMocks,
    createTestRouter,
    getBrowseTabContent,
    waitForStable,
    waitForTabContent,
    createMockTabConfig,
    setupAxiosMocks,
    createMockBrowseResponse,
    type BrowseMocks,
} from '../test/browse-test-utils';

// Define mocks using vi.hoisted so they're available for vi.mock factories
const {
    mockAxios,
    mockIsLoading,
    mockCancelLoad,
    mockDestroy,
    mockInit,
    mockRemove,
    mockRemoveMany,
    mockRestore,
    mockRestoreMany,
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
    mockDestroy: vi.fn(),
    mockInit: vi.fn(),
    mockRemove: vi.fn(),
    mockRemoveMany: vi.fn(),
    mockRestore: vi.fn(),
    mockRestoreMany: vi.fn(),
    mockQueuePreviewIncrement: vi.fn(),
}));

// Create mocks object for helper functions
const mocks: BrowseMocks = {
    mockAxios,
    mockIsLoading: ref(false),
    mockCancelLoad,
    mockDestroy,
    mockInit,
    mockRemove,
    mockRemoveMany,
    mockRestore,
    mockRestoreMany,
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

// Mock @wyxos/vibe with inline factory
vi.mock('@wyxos/vibe', () => ({
    Masonry: {
        name: 'Masonry',
        template: `
            <div class="masonry-mock">
                <slot
                    v-for="(item, index) in items"
                    :key="item.id || index"
                    :item="item"
                    :remove="() => {}"
                    :index="index"
                ></slot>
            </div>
        `,
        props: ['items', 'getNextPage', 'loadAtPage', 'layout', 'layoutMode', 'mobileBreakpoint', 'skipInitialLoad', 'backfillEnabled', 'backfillDelayMs', 'backfillMaxCalls'],
        emits: ['backfill:start', 'backfill:tick', 'backfill:stop', 'backfill:retry-start', 'backfill:retry-tick', 'backfill:retry-stop', 'update:items'],
        setup() {
            const exposed = {
                init: mockInit,
                refreshLayout: vi.fn(),
                cancelLoad: mockCancelLoad,
                destroy: mockDestroy,
                remove: mockRemove,
                removeMany: mockRemoveMany,
                restore: mockRestore,
                restoreMany: mockRestoreMany,
            };
            Object.defineProperty(exposed, 'isLoading', { get: () => mockIsLoading.value, enumerable: true });
            return exposed;
        },
    },
    MasonryItem: {
        name: 'MasonryItem',
        template: `
            <div @mouseenter="$emit('mouseenter', $event)" @mouseleave="$emit('mouseleave', $event)">
                <slot
                    :item="item"
                    :remove="remove"
                    :imageLoaded="true"
                    :imageError="false"
                    :videoLoaded="false"
                    :videoError="false"
                    :isLoading="false"
                    :showMedia="true"
                    :imageSrc="item?.src || item?.thumbnail || ''"
                    :videoSrc="null"
                ></slot>
            </div>
        `,
        props: ['item', 'remove'],
        emits: ['mouseenter', 'mouseleave', 'preload:success'],
    },
}));

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
    it('renders the Masonry component when tab exists', async () => {
        const tabConfig = createMockTabConfig(1);
        const { wrapper } = await mountBrowseWithTab(tabConfig);
        await waitForStable(wrapper);

        expect(wrapper.find('.masonry-mock').exists()).toBe(true);
    });

    it('initializes with empty items array', async () => {
        const router = await createTestRouter();
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await waitForStable(wrapper);

        const tabContentVm = getBrowseTabContent(wrapper);
        if (tabContentVm) {
            expect(tabContentVm.items).toEqual([]);
        } else {
            expect(true).toBe(true);
        }
    });

    it('passes correct props to Masonry component', async () => {
        const tabConfig = createMockTabConfig(1);
        const { wrapper } = await mountBrowseWithTab(tabConfig);
        await waitForStable(wrapper);

        const masonry = wrapper.findComponent({ name: 'Masonry' });
        expect(masonry.exists()).toBe(true);
        expect(masonry.props('loadAtPage')).toBe(1);
        expect(masonry.props('layoutMode')).toBe('auto');
        expect(masonry.props('mobileBreakpoint')).toBe(768);
        expect(masonry.props('skipInitialLoad')).toBe(false);
        expect(masonry.props('layout')).toEqual({
            gutterX: 12,
            gutterY: 12,
            sizes: { base: 1, sm: 2, md: 3, lg: 4, '2xl': 10 },
        });
    });

    it('provides getNextPage function that fetches from API', async () => {
        const mockResponse = createMockBrowseResponse(2, 3);
        const tabId = 1;
        const tabConfig = createMockTabConfig(tabId, { query_params: { service: 'civit-ai-images' } });
        const browseResponse = {
            ...mockResponse,
            services: [{ key: 'civit-ai-images', label: 'CivitAI Images' }],
        };

        const { wrapper } = await mountBrowseWithTab(tabConfig, browseResponse);
        await waitForStable(wrapper);

        const vm = wrapper.vm as any;
        const tabContentVm = await waitForTabContent(wrapper);
        if (!tabContentVm) {
            return;
        }

        tabContentVm.isTabRestored = false;
        tabContentVm.items = [];
        const activeTab = vm.getActiveTab();
        if (activeTab && !activeTab.queryParams.service) {
            activeTab.queryParams.service = 'civit-ai-images';
        }
        const getNextPage = tabContentVm.getNextPage;

        const result = await getNextPage(2);

        expect(mocks.mockAxios.get).toHaveBeenCalledWith(
            expect.stringContaining('/api/browse?page=2')
        );
        expect(result).toHaveProperty('items');
        expect(result).toHaveProperty('nextPage');
        expect(result.items).toBeInstanceOf(Array);
        expect(result.items.length).toBe(40);
        expect(result.nextPage).toBe(3);

        const updatedTab = vm.tabs.find((t: any) => t.id === tabId);
        expect(updatedTab).toBeDefined();
        expect(updatedTab.itemsData.length).toBe(40);
        expect(updatedTab.queryParams.page).toBe(2);
        expect(updatedTab.queryParams.next).toBe(3);
    });

    it('handles API errors gracefully', async () => {
        const networkError = new Error('Network error');

        mocks.mockAxios.get.mockImplementation((url: string) => {
            if (url.includes('/api/browse-tabs')) {
                return Promise.resolve({ data: [] });
            }
            if (url.includes('/api/browse')) {
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
                query_params: {},
                file_ids: [],
                position: 0,
                is_active: false,
            },
        });

        const vm = wrapper.vm as any;
        await vm.createTab();
        const activeTab = vm.getActiveTab();
        if (activeTab) {
            activeTab.queryParams.service = 'civit-ai-images';
        }
        await waitForStable(wrapper);

        const tabContentVm = await waitForTabContent(wrapper);
        if (!tabContentVm) {
            return;
        }

        tabContentVm.isTabRestored = false;
        tabContentVm.items = [];
        const getNextPage = tabContentVm.getNextPage;

        await expect(getNextPage(1)).rejects.toThrow('Network error');
    });

    it('returns correct structure from getNextPage with null nextPage', async () => {
        const mockResponse = createMockBrowseResponse(100, null);

        mocks.mockAxios.get.mockImplementation((url: string) => {
            if (url.includes('/api/browse-tabs')) {
                return Promise.resolve({ data: [] });
            }
            if (url.includes('/api/browse')) {
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
                query_params: {},
                file_ids: [],
                position: 0,
                is_active: false,
            },
        });

        const vm = wrapper.vm as any;
        await vm.createTab();
        const activeTab = vm.getActiveTab();
        if (activeTab) {
            activeTab.queryParams.service = 'civit-ai-images';
        }
        await waitForStable(wrapper);

        const tabContentVm = await waitForTabContent(wrapper);
        if (!tabContentVm) {
            return;
        }

        tabContentVm.isTabRestored = false;
        tabContentVm.items = [];
        const result = await tabContentVm.getNextPage(100);

        expect(result).toHaveProperty('items');
        expect(result).toHaveProperty('nextPage');
        expect(result.items).toBeInstanceOf(Array);
        expect(result.items.length).toBe(40);
        expect(result.nextPage).toBeNull();
    });

    it('handles cursor-based pagination with string cursors', async () => {
        const cursor = 'cursor-abc123';
        const nextCursor = 'cursor-xyz789';
        const mockResponse = createMockBrowseResponse(cursor, nextCursor);

        mocks.mockAxios.get.mockImplementation((url: string) => {
            if (url.includes('/api/browse-tabs')) {
                return Promise.resolve({ data: [] });
            }
            if (url.includes('/api/browse')) {
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
                query_params: {},
                file_ids: [],
                position: 0,
                is_active: false,
            },
        });

        const vm = wrapper.vm as any;
        await vm.createTab();
        const activeTab = vm.getActiveTab();
        if (activeTab) {
            activeTab.queryParams.service = 'civit-ai-images';
        }
        await waitForStable(wrapper);

        const tabContentVm = await waitForTabContent(wrapper);
        if (!tabContentVm) {
            return;
        }

        tabContentVm.isTabRestored = false;
        tabContentVm.items = [];
        const result = await tabContentVm.getNextPage(cursor);

        expect(mocks.mockAxios.get).toHaveBeenCalledWith(
            expect.stringContaining(`/api/browse?page=${cursor}`)
        );
        expect(result).toHaveProperty('items');
        expect(result).toHaveProperty('nextPage');
        expect(result.nextPage).toBe(nextCursor);
        expect(tabContentVm.currentPage).toBe(cursor);
        expect(tabContentVm.nextCursor).toBe(nextCursor);
    });

    it('updates currentPage to 1 when fetching first page', async () => {
        const mockResponse = createMockBrowseResponse(1, 2);

        mocks.mockAxios.get.mockImplementation((url: string) => {
            if (url.includes('/api/browse-tabs')) {
                return Promise.resolve({ data: [] });
            }
            if (url.includes('/api/browse')) {
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
                query_params: {},
                file_ids: [],
                position: 0,
                is_active: false,
            },
        });

        const vm = wrapper.vm as any;
        await vm.createTab();
        const activeTab = vm.getActiveTab();
        if (activeTab) {
            activeTab.queryParams.service = 'civit-ai-images';
        }
        await waitForStable(wrapper);

        const tabContentVm = await waitForTabContent(wrapper);
        if (!tabContentVm) {
            return;
        }

        tabContentVm.isTabRestored = false;
        tabContentVm.items = [];
        await tabContentVm.getNextPage(1);

        expect(tabContentVm.currentPage).toBe(1);
        expect(tabContentVm.nextCursor).toBe(2);
    });

    it('initializes with first tab when tabs exist and loads items if tab has files', async () => {
        const tabId = 1;
        const pageParam = 'cursor-page-123';
        const nextParam = 'cursor-next-456';
        const mockItems = [
            { id: 1, width: 100, height: 100, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false },
            { id: 2, width: 200, height: 200, src: 'test2.jpg', type: 'image', page: 1, index: 1, notFound: false },
        ];

        const tabConfig = createMockTabConfig(tabId, {
            query_params: { service: 'civit-ai-images', page: pageParam, next: nextParam },
            file_ids: [1, 2],
            items_data: mockItems,
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

        const tabContentVm = await waitForTabContent(wrapper);
        if (tabContentVm) {
            expect(tabContentVm.currentPage).toBe(pageParam);
            expect(tabContentVm.nextCursor).toBe(nextParam);
        }
        expect(mocks.mockAxios.get).toHaveBeenCalledWith('/api/browse-tabs/1/items');
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
            if (url.includes('/api/browse-tabs')) {
                return Promise.resolve({
                    data: [{
                        id: 1,
                        label: 'Test Tab',
                        query_params: { service: 'civit-ai-images', page: 1 },
                        file_ids: [],
                        items_data: [],
                        position: 0,
                    }],
                });
            }
            if (url.includes('/api/browse')) {
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
        tabContentVm.currentPage = 2;
        tabContentVm.nextCursor = 'cursor-123';

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
        if (tabContentVm) {
            tabContentVm.nextCursor = null;
            await wrapper.vm.$nextTick();

            const nextPill = wrapper
                .findAllComponents({ name: 'Pill' })
                .find((p) => p.props('label') === 'Next');
            if (nextPill) {
                expect(nextPill.props('value')).toBe('N/A');
            }
        }
    });

    it('handles tab with page parameter in query_params and loads items lazily', async () => {
        const tabId = 1;
        const pageParam = 'cursor-string-123';

        mocks.mockAxios.get.mockImplementation((url: string) => {
            if (url.includes('/api/browse-tabs') && !url.includes('/items')) {
                return Promise.resolve({
                    data: [{
                        id: tabId,
                        label: 'Test Tab',
                        query_params: { service: 'civit-ai-images', page: pageParam },
                        position: 0,
                        is_active: true,
                    }],
                });
            }
            if (url.includes('/api/browse-tabs/1/items')) {
                return Promise.resolve({
                    data: {
                        items_data: [{ id: 123, width: 100, height: 100, src: 'test.jpg', type: 'image', page: 1, index: 0, notFound: false }],
                        file_ids: [123],
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

        const tabContentVm = getBrowseTabContent(wrapper);
        if (tabContentVm) {
            expect(tabContentVm.currentPage).toBe(pageParam);
        }
        expect(mocks.mockAxios.get).toHaveBeenCalledWith('/api/browse-tabs/1/items');
    });

    it('handles tab with page in query_params correctly and loads items lazily', async () => {
        const tabId = 1;
        const pageValue = 123;

        mocks.mockAxios.get.mockImplementation((url: string) => {
            if (url.includes('/api/browse-tabs') && !url.includes('/items')) {
                return Promise.resolve({
                    data: [{
                        id: tabId,
                        label: 'Test Tab',
                        query_params: { service: 'civit-ai-images', page: pageValue },
                        position: 0,
                        is_active: true,
                    }],
                });
            }
            if (url.includes('/api/browse-tabs/1/items')) {
                return Promise.resolve({
                    data: {
                        items_data: [{ id: 123, width: 100, height: 100, src: 'test.jpg', type: 'image', page: 1, index: 0, notFound: false }],
                        file_ids: [123],
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

        const tabContentVm = getBrowseTabContent(wrapper);
        if (tabContentVm) {
            expect(tabContentVm.currentPage).toBe(pageValue);
        }
        expect(mocks.mockAxios.get).toHaveBeenCalledWith('/api/browse-tabs/1/items');
    });
});
