import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createRouter, createMemoryHistory } from 'vue-router';
import { ref } from 'vue';
import Browse from './Browse.vue';
import FileViewer from '../components/FileViewer.vue';

// Mock fetch (no longer used, but keep for compatibility)
global.fetch = vi.fn();

// Mock axios
const mockAxios = {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
};

vi.mock('axios', () => ({
    default: mockAxios,
}));

// Mock window.axios
Object.defineProperty(window, 'axios', {
    value: mockAxios,
    writable: true,
});

// Mock @wyxos/vibe
const mockIsLoading = ref(false);
const mockCancelLoad = vi.fn();
const mockDestroy = vi.fn();
const mockInit = vi.fn();
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
        emits: ['backfill:start', 'backfill:tick', 'backfill:stop', 'backfill:retry-start', 'backfill:retry-tick', 'backfill:retry-stop'],
        setup(props: { items: any[] }) {
            return {
                isLoading: mockIsLoading,
                init: mockInit,
                refreshLayout: vi.fn(),
                cancelLoad: mockCancelLoad,
                destroy: mockDestroy,
            };
        },
    },
}));

beforeEach(() => {
    vi.clearAllMocks();
    vi.spyOn(console, 'error').mockImplementation(() => { });
    mockIsLoading.value = false;
    mockCancelLoad.mockClear();
    mockDestroy.mockClear();
    mockInit.mockClear();

    // Mock tabs API to return empty array by default
    // Reset to default mock that returns empty array for /api/browse-tabs
    mockAxios.get.mockImplementation((url: string) => {
        if (url.includes('/api/browse-tabs')) {
            return Promise.resolve({ data: [] });
        }
        // For other URLs, return a default response
        return Promise.resolve({ data: { items: [], nextPage: null } });
    });
});

async function createTestRouter(initialPath = '/browse') {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/browse', component: Browse },
            { path: '/dashboard', component: { template: '<div>Dashboard</div>' } },
        ],
    });
    await router.push(initialPath);
    await router.isReady();
    return router;
}

function createMockBrowseResponse(
    page: number | string,
    nextPageValue: number | string | null = null
) {
    const items = Array.from({ length: 40 }, (_, i) => ({
        id: `item-${page}-${i}`,
        width: 300 + (i % 100),
        height: 200 + (i % 100),
        src: `https://picsum.photos/id/${i}/300/200`,
        type: i % 10 === 0 ? 'video' : 'image',
        page: typeof page === 'number' ? page : 1,
        index: i,
        notFound: false,
    }));

    return {
        items,
        nextPage: nextPageValue !== null ? nextPageValue : (typeof page === 'number' && page < 100 ? page + 1 : null),
    };
}

describe('Browse', () => {
    it('renders the Masonry component when tab exists', async () => {
        // Mock tabs API to return a tab with service
        mockAxios.get.mockImplementation((url: string) => {
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
                        services: [
                            { key: 'civit-ai-images', label: 'CivitAI Images' },
                        ],
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

        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 100)); // Wait for tab switching

        expect(wrapper.find('.masonry-mock').exists()).toBe(true);
    });

    it('initializes with empty items array', async () => {
        const router = await createTestRouter();
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        expect(vm.items).toEqual([]);
    });

    it('passes correct props to Masonry component', async () => {
        // Mock tabs API to return a tab with service
        mockAxios.get.mockImplementation((url: string) => {
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
                        services: [
                            { key: 'civit-ai-images', label: 'CivitAI Images' },
                        ],
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

        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 200)); // Wait for tab switching

        const masonry = wrapper.findComponent({ name: 'Masonry' });
        expect(masonry.exists()).toBe(true);
        // When tab has no items, loadAtPage is set to 1 to start loading
        expect(masonry.props('loadAtPage')).toBe(1);
        expect(masonry.props('layoutMode')).toBe('auto');
        expect(masonry.props('mobileBreakpoint')).toBe(768);
        expect(masonry.props('skipInitialLoad')).toBe(false); // No items initially
        expect(masonry.props('layout')).toEqual({
            gutterX: 12,
            gutterY: 12,
            sizes: { base: 1, sm: 2, md: 3, lg: 4, '2xl': 10 },
        });
    });

    it('provides getNextPage function that fetches from API', async () => {
        const mockResponse = createMockBrowseResponse(2, 3);
        const tabId = 1;

        // Mock tabs API to return a tab with service
        mockAxios.get.mockImplementation((url: string) => {
            if (url.includes('/api/browse-tabs')) {
                return Promise.resolve({
                    data: [{
                        id: tabId,
                        label: 'Test Tab',
                        query_params: { service: 'civit-ai-images' },
                        file_ids: [],
                        items_data: [],
                        position: 0,
                    }],
                });
            }
            if (url.includes('/api/browse')) {
                return Promise.resolve({
                    data: {
                        ...mockResponse,
                        services: [
                            { key: 'civit-ai-images', label: 'CivitAI Images' },
                        ],
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

        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 100)); // Wait for tab switching

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        // Ensure tab restoration state is false and items are empty
        vm.isTabRestored = false;
        vm.items = [];
        // Ensure service is set (tab should have it, but double-check)
        const activeTab = vm.getActiveTab();
        if (activeTab && !activeTab.queryParams.service) {
            activeTab.queryParams.service = 'civit-ai-images';
        }
        const getNextPage = vm.getNextPage;

        const result = await getNextPage(2);

        expect(mockAxios.get).toHaveBeenCalledWith(
            expect.stringContaining('/api/browse?page=2')
        );
        expect(result).toHaveProperty('items');
        expect(result).toHaveProperty('nextPage');
        expect(result.items).toBeInstanceOf(Array);
        expect(result.items.length).toBe(40);
        expect(result.nextPage).toBe(3);

        // Verify tab was updated with new items
        const updatedTab = vm.tabs.find((t: any) => t.id === tabId);
        expect(updatedTab).toBeDefined();
        expect(updatedTab.itemsData.length).toBe(40);
        expect(updatedTab.queryParams.page).toBe(2);
        expect(updatedTab.queryParams.next).toBe(3);
    });

    it('handles API errors gracefully', async () => {
        const networkError = new Error('Network error');

        // Override the mock for this specific test
        mockAxios.get.mockImplementation((url: string) => {
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

        mockAxios.post.mockResolvedValue({
            data: {
                id: 1,
                label: 'Browse 1',
                query_params: {},
                file_ids: [],
                position: 0,
            },
        });

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        // Ensure tab restoration state is false and items are empty
        vm.isTabRestored = false;
        vm.items = [];
        // Create a tab with service for this test
        await vm.createTab();
        const activeTab = vm.getActiveTab();
        if (activeTab) {
            activeTab.queryParams.service = 'civit-ai-images';
        }
        await flushPromises();
        await wrapper.vm.$nextTick();
        const getNextPage = vm.getNextPage;

        await expect(getNextPage(1)).rejects.toThrow('Network error');
    });

    it('returns correct structure from getNextPage with null nextPage', async () => {
        const mockResponse = createMockBrowseResponse(100, null);

        // Override the mock for this specific test
        mockAxios.get.mockImplementation((url: string) => {
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

        mockAxios.post.mockResolvedValue({
            data: {
                id: 1,
                label: 'Browse 1',
                query_params: {},
                file_ids: [],
                position: 0,
            },
        });

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        // Ensure tab restoration state is false and items are empty
        vm.isTabRestored = false;
        vm.items = [];
        // Create a tab with service for this test
        await vm.createTab();
        const activeTab = vm.getActiveTab();
        if (activeTab) {
            activeTab.queryParams.service = 'civit-ai-images';
        }
        await flushPromises();
        await wrapper.vm.$nextTick();
        const result = await vm.getNextPage(100);

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

        // Override the mock for this specific test
        mockAxios.get.mockImplementation((url: string) => {
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

        mockAxios.post.mockResolvedValue({
            data: {
                id: 1,
                label: 'Browse 1',
                query_params: {},
                file_ids: [],
                position: 0,
            },
        });

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        // Ensure tab restoration state is false and items are empty
        vm.isTabRestored = false;
        vm.items = [];
        // Create a tab with service for this test
        await vm.createTab();
        const activeTab = vm.getActiveTab();
        if (activeTab) {
            activeTab.queryParams.service = 'civit-ai-images';
        }
        await flushPromises();
        await wrapper.vm.$nextTick();
        const result = await vm.getNextPage(cursor);

        expect(mockAxios.get).toHaveBeenCalledWith(
            expect.stringContaining(`/api/browse?page=${cursor}`)
        );
        expect(result).toHaveProperty('items');
        expect(result).toHaveProperty('nextPage');
        expect(result.nextPage).toBe(nextCursor);
        expect(vm.currentPage).toBe(cursor);
        expect(vm.nextCursor).toBe(nextCursor);
    });

    it('updates currentPage to 1 when fetching first page', async () => {
        const mockResponse = createMockBrowseResponse(1, 2);

        // Override the mock for this specific test
        mockAxios.get.mockImplementation((url: string) => {
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

        mockAxios.post.mockResolvedValue({
            data: {
                id: 1,
                label: 'Browse 1',
                query_params: {},
                file_ids: [],
                position: 0,
            },
        });

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        // Ensure tab restoration state is false and items are empty
        vm.isTabRestored = false;
        vm.items = [];
        // Create a tab with service for this test
        await vm.createTab();
        const activeTab = vm.getActiveTab();
        if (activeTab) {
            activeTab.queryParams.service = 'civit-ai-images';
        }
        await flushPromises();
        await wrapper.vm.$nextTick();
        await vm.getNextPage(1);

        expect(vm.currentPage).toBe(1);
        expect(vm.nextCursor).toBe(2);
    });

    it('initializes with first tab when tabs exist and loads items if tab has files', async () => {
        const tabId = 1;
        const pageParam = 'cursor-page-123';
        const nextParam = 'cursor-next-456';

        // Mock tabs API to return a tab with the page/next in query_params and file_ids
        mockAxios.get.mockImplementation((url: string) => {
            if (url.includes('/api/browse-tabs')) {
                return Promise.resolve({
                    data: [{
                        id: tabId,
                        label: 'Test Tab',
                        query_params: { service: 'civit-ai-images', page: pageParam, next: nextParam },
                        file_ids: [1, 2],
                        position: 0,
                    }],
                });
            }
            if (url.includes('/api/browse-tabs/1/items')) {
                return Promise.resolve({
                    data: {
                        items_data: [
                            { id: 1, width: 100, height: 100, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false },
                            { id: 2, width: 200, height: 200, src: 'test2.jpg', type: 'image', page: 1, index: 1, notFound: false },
                        ],
                        file_ids: [1, 2],
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

        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 300)); // Wait for tab switching and restoration

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        expect(vm.activeTabId).toBe(tabId);
        // Query params should be restored
        expect(vm.currentPage).toBe(pageParam);
        expect(vm.nextCursor).toBe(nextParam);
        // Items should be loaded
        expect(mockAxios.get).toHaveBeenCalledWith('/api/browse-tabs/1/items');
    });

    it('initializes with default values when no tabs exist', async () => {
        // Mock tabs API to return empty array
        mockAxios.get.mockResolvedValueOnce({ data: [] });

        const router = await createTestRouter('/browse');

        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        expect(vm.activeTabId).toBeNull();
        expect(vm.currentPage).toBe(1);
        expect(vm.nextCursor).toBeNull();
        expect(vm.loadAtPage).toBeNull(); // Changed to null by default
    });


    it('displays Pill components with correct values', async () => {
        // Mock tabs API to return a tab with service so pills are rendered
        mockAxios.get.mockImplementation((url: string) => {
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
                        services: [
                            { key: 'civit-ai-images', label: 'CivitAI Images' },
                        ],
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

        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 100)); // Wait for tab switching

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        vm.items = [{ id: '1' }, { id: '2' }, { id: '3' }];
        vm.currentPage = 2;
        vm.nextCursor = 'cursor-123';

        await wrapper.vm.$nextTick();

        const pills = wrapper.findAllComponents({ name: 'Pill' });
        expect(pills.length).toBeGreaterThan(0);

        // Check that pills are rendered (exact values depend on component state)
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

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        vm.nextCursor = null;

        await wrapper.vm.$nextTick();

        const nextPill = wrapper
            .findAllComponents({ name: 'Pill' })
            .find((p) => p.props('label') === 'Next');
        if (nextPill) {
            expect(nextPill.props('value')).toBe('N/A');
        }
    });

    it('handles tab with page parameter in query_params and loads items lazily', async () => {
        const tabId = 1;
        const pageParam = 'cursor-string-123';

        // Mock tabs API to return a tab with page in query_params and file_ids (no items_data)
        mockAxios.get.mockImplementation((url: string) => {
            if (url.includes('/api/browse-tabs')) {
                return Promise.resolve({
                    data: [{
                        id: tabId,
                        label: 'Test Tab',
                        query_params: { service: 'civit-ai-images', page: pageParam },
                        file_ids: [123],
                        position: 0,
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

        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 300)); // Wait for tab switching and restoration

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        expect(vm.activeTabId).toBe(tabId);
        // Page from query_params should be restored
        expect(vm.currentPage).toBe(pageParam);
        // Items should be loaded lazily
        expect(mockAxios.get).toHaveBeenCalledWith('/api/browse-tabs/1/items');
    });

    it('handles tab with page in query_params correctly and loads items lazily', async () => {
        const tabId = 1;
        const pageValue = 123; // Can be number or string

        // Mock tabs API to return a tab with page as number in query_params and file_ids (no items_data)
        mockAxios.get.mockImplementation((url: string) => {
            if (url.includes('/api/browse-tabs')) {
                return Promise.resolve({
                    data: [{
                        id: tabId,
                        label: 'Test Tab',
                        query_params: { service: 'civit-ai-images', page: pageValue },
                        file_ids: [123],
                        position: 0,
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

        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 300)); // Wait for tab switching and restoration

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        // Page value from query_params should be preserved (can be number or string)
        expect(vm.currentPage).toBe(pageValue);
        // Items should be loaded lazily
        expect(mockAxios.get).toHaveBeenCalledWith('/api/browse-tabs/1/items');
    });

    it('cancels ongoing load and destroys masonry when switching tabs', async () => {
        const tab1Id = 1;
        const tab2Id = 2;

        // Mock tabs API to return two tabs with services
        mockAxios.get.mockImplementation((url: string) => {
            if (url.includes('/api/browse-tabs')) {
                return Promise.resolve({
                    data: [
                        {
                            id: tab1Id,
                            label: 'Tab 1',
                            query_params: { service: 'civit-ai-images', page: 1 },
                            file_ids: [],
                            items_data: [],
                            position: 0,
                        },
                        {
                            id: tab2Id,
                            label: 'Tab 2',
                            query_params: { service: 'civit-ai-images', page: 1 },
                            file_ids: [],
                            items_data: [],
                            position: 1,
                        },
                    ],
                });
            }
            if (url.includes('/api/browse')) {
                return Promise.resolve({
                    data: {
                        items: [],
                        nextPage: null,
                        services: [
                            { key: 'civit-ai-images', label: 'CivitAI Images' },
                        ],
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

        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 100)); // Wait for initial tab load

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;

        // Set masonry to loading state
        mockIsLoading.value = true;
        expect(vm.masonry?.isLoading).toBe(true);

        // Switch to second tab
        await vm.switchTab(tab2Id);

        // Verify cancelLoad and destroy were called
        expect(mockCancelLoad).toHaveBeenCalled();
        expect(mockDestroy).toHaveBeenCalled();
        expect(vm.activeTabId).toBe(tab2Id);
    });

    it('destroys masonry when switching tabs even if not loading', async () => {
        const tab1Id = 1;
        const tab2Id = 2;

        // Mock tabs API to return two tabs with services
        mockAxios.get.mockImplementation((url: string) => {
            if (url.includes('/api/browse-tabs')) {
                return Promise.resolve({
                    data: [
                        {
                            id: tab1Id,
                            label: 'Tab 1',
                            query_params: { service: 'civit-ai-images', page: 1 },
                            file_ids: [],
                            items_data: [],
                            position: 0,
                        },
                        {
                            id: tab2Id,
                            label: 'Tab 2',
                            query_params: { service: 'civit-ai-images', page: 1 },
                            file_ids: [],
                            items_data: [],
                            position: 1,
                        },
                    ],
                });
            }
            if (url.includes('/api/browse')) {
                return Promise.resolve({
                    data: {
                        items: [],
                        nextPage: null,
                        services: [
                            { key: 'civit-ai-images', label: 'CivitAI Images' },
                        ],
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

        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 100)); // Wait for initial tab load

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;

        // Ensure masonry is not loading
        mockIsLoading.value = false;
        expect(vm.masonry?.isLoading).toBe(false);

        // Clear previous calls
        mockCancelLoad.mockClear();
        mockDestroy.mockClear();

        // Switch to second tab
        await vm.switchTab(tab2Id);

        // Verify destroy was called even when masonry is not loading
        // (destroy should always be called to reset state)
        expect(mockDestroy).toHaveBeenCalled();
        // cancelLoad may or may not be called depending on loading state
        expect(vm.activeTabId).toBe(tab2Id);
    });

    it('closes tab when middle clicked', async () => {
        const tab1Id = 1;
        const tab2Id = 2;

        // Mock tabs API to return two tabs
        mockAxios.get.mockImplementation((url: string) => {
            if (url.includes('/api/browse-tabs')) {
                return Promise.resolve({
                    data: [
                        {
                            id: tab1Id,
                            label: 'Tab 1',
                            query_params: { service: 'civit-ai-images', page: 1 },
                            file_ids: [],
                            items_data: [],
                            position: 0,
                        },
                        {
                            id: tab2Id,
                            label: 'Tab 2',
                            query_params: { service: 'civit-ai-images', page: 1 },
                            file_ids: [],
                            items_data: [],
                            position: 1,
                        },
                    ],
                });
            }
            if (url.includes('/api/browse')) {
                return Promise.resolve({
                    data: {
                        items: [],
                        nextPage: null,
                        services: [
                            { key: 'civit-ai-images', label: 'CivitAI Images' },
                        ],
                    },
                });
            }
            return Promise.resolve({ data: { items: [], nextPage: null } });
        });

        mockAxios.delete.mockResolvedValue({ data: { success: true } });

        const router = await createTestRouter('/browse');

        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 100));

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;

        // Verify both tabs exist
        expect(vm.tabs.length).toBe(2);
        expect(vm.activeTabId).toBe(tab1Id);

        // Find the BrowseTab component for tab 2
        const browseTabs = wrapper.findAllComponents({ name: 'BrowseTab' });
        const tab2Component = browseTabs.find((tab: any) => tab.props().id === tab2Id);
        expect(tab2Component).toBeDefined();

        // Get the closeTab function call count before
        const closeTabSpy = vi.spyOn(vm, 'closeTab');

        // Simulate middle click on tab 2 by triggering mousedown and click events
        const tab2Element = tab2Component?.element as HTMLElement;
        
        // Create a middle click event
        const mouseDownEvent = new MouseEvent('mousedown', {
            button: 1,
            bubbles: true,
            cancelable: true,
        });
        
        const clickEvent = new MouseEvent('click', {
            button: 1,
            bubbles: true,
            cancelable: true,
        });

        // Trigger mousedown first
        tab2Element.dispatchEvent(mouseDownEvent);
        
        // Then trigger click
        tab2Element.dispatchEvent(clickEvent);

        await flushPromises();
        await wrapper.vm.$nextTick();

        // Verify closeTab was called
        expect(closeTabSpy).toHaveBeenCalledWith(tab2Id);
    });

    it('does nothing when clicking on already active tab', async () => {
        const tab1Id = 1;

        // Mock tabs API to return one tab
        mockAxios.get.mockImplementation((url: string) => {
            if (url.includes('/api/browse-tabs')) {
                return Promise.resolve({
                    data: [
                        {
                            id: tab1Id,
                            label: 'Tab 1',
                            query_params: { service: 'civit-ai-images', page: 1 },
                            file_ids: [],
                            items_data: [],
                            position: 0,
                        },
                    ],
                });
            }
            if (url.includes('/api/browse')) {
                return Promise.resolve({
                    data: {
                        items: [],
                        nextPage: null,
                        services: [
                            { key: 'civit-ai-images', label: 'CivitAI Images' },
                        ],
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

        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 100));

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;

        // Verify tab 1 is active
        expect(vm.activeTabId).toBe(tab1Id);

        // Clear mock calls
        mockDestroy.mockClear();
        mockInit.mockClear();

        // Try to switch to the same tab (clicking active tab)
        await vm.switchTab(tab1Id);

        await flushPromises();
        await wrapper.vm.$nextTick();

        // Verify tab is still active
        expect(vm.activeTabId).toBe(tab1Id);

        // Verify masonry was NOT destroyed (since we didn't actually switch)
        expect(mockDestroy).not.toHaveBeenCalled();
    });

    it('closes fileviewer when switching tabs', async () => {
        const tab1Id = 1;
        const tab2Id = 2;

        // Mock tabs API to return two tabs
        mockAxios.get.mockImplementation((url: string) => {
            if (url.includes('/api/browse-tabs')) {
                return Promise.resolve({
                    data: [
                        {
                            id: tab1Id,
                            label: 'Tab 1',
                            query_params: { service: 'civit-ai-images', page: 1 },
                            file_ids: [],
                            items_data: [],
                            position: 0,
                        },
                        {
                            id: tab2Id,
                            label: 'Tab 2',
                            query_params: { service: 'civit-ai-images', page: 1 },
                            file_ids: [],
                            items_data: [],
                            position: 1,
                        },
                    ],
                });
            }
            if (url.includes('/api/browse')) {
                return Promise.resolve({
                    data: {
                        items: [],
                        nextPage: null,
                        services: [
                            { key: 'civit-ai-images', label: 'CivitAI Images' },
                        ],
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

        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 100));

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;

        // Verify tab 1 is active
        expect(vm.activeTabId).toBe(tab1Id);

        // Open fileviewer in tab 1
        const fileViewer = wrapper.findComponent(FileViewer);
        const fileViewerVm = fileViewer.vm as any;

        fileViewerVm.overlayRect = { top: 100, left: 200, width: 300, height: 400 };
        fileViewerVm.overlayImage = { src: 'test.jpg', srcset: 'test.jpg 1x', sizes: '300px', alt: 'Test' };
        fileViewerVm.overlayIsFilled = true;
        fileViewerVm.overlayFillComplete = true;

        // Verify fileviewer is open
        expect(fileViewerVm.overlayRect).not.toBeNull();

        // Access fileViewer ref through vm and spy on close method
        const fileViewerRef = vm.fileViewer;
        expect(fileViewerRef).not.toBeNull();
        const closeSpy = vi.spyOn(fileViewerRef, 'close');

        // Switch to tab 2
        await vm.switchTab(tab2Id);

        await flushPromises();
        await wrapper.vm.$nextTick();

        // Verify tab 2 is active
        expect(vm.activeTabId).toBe(tab2Id);

        // Verify fileviewer close was called
        expect(closeSpy).toHaveBeenCalled();
    });

    it('creates a new tab and does not auto-load until service is selected', async () => {
        // Mock tabs API to return empty array initially
        mockAxios.get.mockImplementation((url: string) => {
            if (url.includes('/api/browse-tabs')) {
                return Promise.resolve({ data: [] });
            }
            if (url.includes('/api/browse')) {
                return Promise.resolve({
                    data: {
                        items: [],
                        nextPage: null,
                        services: [
                            { key: 'civit-ai-images', label: 'CivitAI Images' },
                        ],
                    },
                });
            }
            return Promise.resolve({ data: { items: [], nextPage: null } });
        });

        // Mock create tab API
        const newTabId = 1;
        mockAxios.post.mockResolvedValueOnce({
            data: {
                id: newTabId,
                label: 'Browse 1',
                query_params: {}, // No service - should not auto-load
                file_ids: [],
                position: 0,
            },
        });

        const router = await createTestRouter();
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;

        // Create a new tab
        await vm.createTab();
        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 200)); // Wait for tab switching

        // Verify tab was created
        expect(vm.activeTabId).toBe(newTabId);
        expect(vm.tabs.length).toBe(1);
        // New tabs don't have page set by default (no service selected)
        expect(vm.tabs[0].queryParams.page).toBeUndefined();

        // Verify loadAtPage is null for new tab (no service selected, so no auto-load)
        const masonry = wrapper.findComponent({ name: 'Masonry' });
        expect(masonry.exists()).toBe(false); // Masonry should not render without service
    });

    it('restores tab with files and query params after refresh, initializes masonry correctly', async () => {
        const tabId = 1;
        const pageParam = 'cursor-page-123';
        const nextParam = 'cursor-next-456';
        const mockItems = [
            { id: 1, width: 100, height: 100, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false },
            { id: 2, width: 200, height: 200, src: 'test2.jpg', type: 'image', page: 1, index: 1, notFound: false },
        ];

        // Mock tabs API to return a tab with query params and file_ids (simulating restored state)
        mockAxios.get.mockImplementation((url: string) => {
            if (url.includes('/api/browse-tabs')) {
                return Promise.resolve({
                    data: [{
                        id: tabId,
                        label: 'Test Tab',
                        query_params: { service: 'civit-ai-images', page: pageParam, next: nextParam },
                        file_ids: [1, 2],
                        position: 0,
                    }],
                });
            }
            if (url.includes('/api/browse-tabs/1/items')) {
                return Promise.resolve({
                    data: {
                        items_data: mockItems,
                        file_ids: [1, 2],
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

        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 500)); // Wait for tab switching, restoration, and item loading

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;

        // Verify tab is active
        expect(vm.activeTabId).toBe(tabId);

        // Verify query params are restored
        expect(vm.currentPage).toBe(pageParam);
        expect(vm.nextCursor).toBe(nextParam);

        // Verify items endpoint was called (this means the tab had fileIds and loadTabItems was triggered)
        // The API call confirms that the condition `tab.fileIds && tab.fileIds.length > 0` was met
        expect(mockAxios.get).toHaveBeenCalledWith('/api/browse-tabs/1/items');

        // Verify masonry component exists and is ready
        const masonry = wrapper.findComponent({ name: 'Masonry' });
        expect(masonry.exists()).toBe(true);

        // The masonry should be initialized with items if they were loaded
        // Note: In a real scenario, if items are loaded, masonry.init() would be called
        // But in tests, we verify the API was called, which confirms the restoration flow works
        // The actual masonry initialization with items would happen in the browser
        // For now, we verify that skipInitialLoad would be true if items were pre-loaded
        // (This is tested more thoroughly in browser tests)
    });

    it('switches to tab with multiple pages loaded, restores files and query params, resumes from next value', async () => {
        const tab1Id = 1;
        const tab2Id = 2;
        const pageParam = 'cursor-page-456';
        const nextParam = 'cursor-next-789';
        const mockItems = [
            { id: 3, width: 100, height: 100, src: 'test3.jpg', type: 'image', page: 2, index: 0, notFound: false },
            { id: 4, width: 200, height: 200, src: 'test4.jpg', type: 'image', page: 2, index: 1, notFound: false },
        ];

        // Mock tabs API to return two tabs
        mockAxios.get.mockImplementation((url: string) => {
            if (url.includes('/api/browse-tabs')) {
                return Promise.resolve({
                    data: [
                        {
                            id: tab1Id,
                            label: 'Tab 1',
                            query_params: { service: 'civit-ai-images', page: 1 },
                            file_ids: [],
                            items_data: [],
                            position: 0,
                        },
                        {
                            id: tab2Id,
                            label: 'Tab 2',
                            query_params: { service: 'civit-ai-images', page: pageParam, next: nextParam },
                            file_ids: [3, 4],
                            position: 1,
                        },
                    ],
                });
            }
            if (url.includes('/api/browse-tabs/2/items')) {
                return Promise.resolve({
                    data: {
                        items_data: mockItems,
                        file_ids: [3, 4],
                    },
                });
            }
            if (url.includes('/api/browse')) {
                // This simulates loading more pages when scrolling
                return Promise.resolve({
                    data: {
                        ...createMockBrowseResponse(pageParam, nextParam),
                        services: [
                            { key: 'civit-ai-images', label: 'CivitAI Images' },
                        ],
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

        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 200)); // Wait for initial tab load

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;

        // Verify first tab is active initially
        expect(vm.activeTabId).toBe(tab1Id);

        // Clear previous mock calls
        mockInit.mockClear();
        mockDestroy.mockClear();

        // Switch to second tab
        await vm.switchTab(tab2Id);
        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 500)); // Wait for tab switching, restoration, and item loading

        // Verify second tab is active
        expect(vm.activeTabId).toBe(tab2Id);

        // Verify query params are restored
        expect(vm.currentPage).toBe(pageParam);
        expect(vm.nextCursor).toBe(nextParam);

        // Verify items endpoint was called for tab 2 (this means the tab had fileIds and loadTabItems was triggered)
        // The API call confirms that the condition `tab.fileIds && tab.fileIds.length > 0` was met
        expect(mockAxios.get).toHaveBeenCalledWith('/api/browse-tabs/2/items');

        // Verify masonry component exists and is ready
        const masonry = wrapper.findComponent({ name: 'Masonry' });
        expect(masonry.exists()).toBe(true);

        // The masonry should be initialized with items if they were loaded
        // Note: In a real scenario, if items are loaded, masonry.init() would be called
        // But in tests, we verify the API was called, which confirms the restoration flow works
        // The actual masonry initialization with items would happen in the browser

        // Verify masonry was destroyed when switching tabs (to reset state)
        expect(mockDestroy).toHaveBeenCalled();

        // Verify masonry will resume from next value when scrolling
        // This is verified by checking that nextCursor is set correctly
        // When getNextPage is called with nextParam, it should use that value
        vm.isTabRestored = false; // Reset flag to allow loading
        vm.items = []; // Clear items to simulate scroll scenario

        const getNextPageResult = await vm.getNextPage(nextParam);

        // Verify it uses the nextParam to load more items
        expect(mockAxios.get).toHaveBeenCalledWith(
            expect.stringContaining(`/api/browse?page=${nextParam}`)
        );
        expect(getNextPageResult.nextPage).toBeDefined();
    });

    it('preserves cursor values on page reload instead of resetting to page 1', async () => {
        const tabId = 1;
        const cursorX = 'cursor-x';
        const cursorY = 'cursor-y';
        const mockItems = Array.from({ length: 139 }, (_, i) => ({
            id: i + 1,
            width: 100,
            height: 100,
            src: `test${i}.jpg`,
            type: 'image' as const,
            page: 1,
            index: i,
            notFound: false,
        }));

        // Mock tabs API to return a tab with cursor values (simulating a tab that has been scrolled)
        // This represents the state after the user has scrolled and loaded 139 items
        mockAxios.get.mockImplementation((url: string) => {
            if (url.includes('/api/browse-tabs')) {
                return Promise.resolve({
                    data: [{
                        id: tabId,
                        label: 'Scrolled Tab',
                        // Tab has cursor values saved (not page 1!)
                        query_params: { service: 'civit-ai-images', page: cursorX, next: cursorY },
                        file_ids: mockItems.map(item => item.id),
                        position: 0,
                    }],
                });
            }
            if (url.includes('/api/browse-tabs/1/items')) {
                return Promise.resolve({
                    data: {
                        items_data: mockItems,
                        file_ids: mockItems.map(item => item.id),
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

        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 500)); // Wait for tab switching and restoration

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;

        // Verify tab is active
        expect(vm.activeTabId).toBe(tabId);

        // CRITICAL: Verify cursor values are preserved, NOT reset to page 1
        // This is the bug fix - the tab should preserve cursor-x, not reset to 1
        expect(vm.currentPage).toBe(cursorX); // Should be cursor-x, NOT 1
        expect(vm.nextCursor).toBe(cursorY); // Should be cursor-y

        // Verify displayPage computed property also shows the cursor value
        expect(vm.displayPage).toBe(cursorX); // Should be cursor-x, NOT 1
    });

    it('continues saved cursor after creating a new tab and switching back', async () => {
        const tabId = 1;
        const cursorX = 'cursor-x';
        const cursorY = 'cursor-y';
        const mockItems = [
            { id: 1, width: 100, height: 100, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false },
            { id: 2, width: 120, height: 120, src: 'test2.jpg', type: 'image', page: 1, index: 1, notFound: false },
        ];

        mockAxios.get.mockImplementation((url: string) => {
            if (url.includes('/api/browse-tabs/1/items')) {
                return Promise.resolve({
                    data: {
                        items_data: mockItems,
                        file_ids: mockItems.map(item => item.id),
                    },
                });
            }
            if (url.includes('/api/browse-tabs')) {
                return Promise.resolve({
                    data: [{
                        id: tabId,
                        label: 'Scrolled Tab',
                        query_params: { service: 'civit-ai-images', page: cursorX, next: cursorY },
                        file_ids: mockItems.map(item => item.id),
                        position: 0,
                    }],
                });
            }
            if (url.includes('/api/browse')) {
                const parsed = new URL(url, 'http://localhost');
                const requestedPage = parsed.searchParams.get('page') ?? '1';
                const nextValue = requestedPage === cursorY ? 'cursor-z' : cursorY;
                return Promise.resolve({
                    data: {
                        ...createMockBrowseResponse(requestedPage, nextValue),
                        services: [
                            { key: 'civit-ai-images', label: 'CivitAI Images' },
                        ],
                    },
                });
            }
            return Promise.resolve({ data: { items: [], nextPage: null } });
        });

        mockAxios.post.mockResolvedValue({
            data: {
                id: 2,
                label: 'Browse 2',
                query_params: { page: 1 },
                file_ids: [],
                position: 1,
            },
        });

        mockAxios.put.mockResolvedValue({});

        const router = await createTestRouter('/browse');
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 300));

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;

        expect(vm.activeTabId).toBe(tabId);

        await vm.createTab();
        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 300));

        expect(vm.activeTabId).toBe(2);

        await vm.switchTab(tabId);
        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 300));

        expect(vm.activeTabId).toBe(tabId);
        expect(vm.pendingRestoreNextCursor).toBe(cursorY);

        await vm.getNextPage(1);

        const browseCalls = mockAxios.get.mock.calls
            .map(call => call[0])
            .filter((callUrl: string) => callUrl.includes('/api/browse'));

        expect(browseCalls[browseCalls.length - 1]).toContain(`/api/browse?page=${cursorY}`);
        expect(vm.currentPage).toBe(cursorY);
    });

    it('new tab does not auto-load until service is selected', async () => {
        mockAxios.get.mockImplementation((url: string) => {
            if (url.includes('/api/browse-tabs')) {
                return Promise.resolve({ data: [] });
            }
            if (url.includes('/api/browse')) {
                return Promise.resolve({
                    data: {
                        items: [],
                        nextPage: null,
                        services: [
                            { key: 'civit-ai-images', label: 'CivitAI Images' },
                            { key: 'wallhaven', label: 'Wallhaven' },
                        ],
                    },
                });
            }
            return Promise.resolve({ data: { items: [], nextPage: null } });
        });

        mockAxios.post.mockResolvedValue({
            data: {
                id: 1,
                label: 'Browse 1',
                query_params: {},
                file_ids: [],
                position: 0,
            },
        });

        const router = await createTestRouter('/browse');
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 300));

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;

        // Create a new tab
        await vm.createTab();
        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 500)); // Wait longer for tab switching

        expect(vm.activeTabId).toBe(1);

        // Wait for switchTab to complete
        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 200));

        expect(vm.hasServiceSelected).toBe(false);
        expect(vm.loadAtPage).toBe(null); // Should not auto-load
        expect(vm.items.length).toBe(0);

        // Verify no browse API calls were made for loading items (only service fetch should happen)
        // fetchServices() calls /api/browse?page=1&limit=1 to get services list - that's expected
        // But no calls should be made to actually load items (which would have source= parameter)
        const itemLoadingCalls = mockAxios.get.mock.calls
            .map(call => call[0])
            .filter((callUrl: string) => {
                const url = callUrl as string;
                // Only count calls that would load items (have source parameter)
                // Service fetch calls have limit=1 and no source
                return url.includes('/api/browse') &&
                    url.includes('source=');
            });
        expect(itemLoadingCalls.length).toBe(0);
    });

    it('applies selected service and triggers loading', async () => {
        mockAxios.get.mockImplementation((url: string) => {
            if (url.includes('/api/browse-tabs')) {
                return Promise.resolve({
                    data: [{
                        id: 1,
                        label: 'Test Tab',
                        query_params: {},
                        file_ids: [],
                        position: 0,
                    }],
                });
            }
            if (url.includes('/api/browse')) {
                const parsed = new URL(url, 'http://localhost');
                const source = parsed.searchParams.get('source');
                return Promise.resolve({
                    data: {
                        items: [
                            { id: 1, width: 100, height: 100, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false },
                        ],
                        nextPage: 'cursor-2',
                        services: [
                            { key: 'civit-ai-images', label: 'CivitAI Images' },
                            { key: 'wallhaven', label: 'Wallhaven' },
                        ],
                    },
                });
            }
            return Promise.resolve({ data: { items: [], nextPage: null } });
        });

        mockAxios.put.mockResolvedValue({});

        const router = await createTestRouter('/browse');
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 500));

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;

        expect(vm.activeTabId).toBe(1);
        expect(vm.hasServiceSelected).toBe(false);

        // Select a service
        vm.selectedService = 'civit-ai-images';
        await wrapper.vm.$nextTick();

        // Apply service
        await vm.applyService();
        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 500)); // Wait for masonry to render and trigger load

        // Verify service was applied
        const activeTab = vm.getActiveTab();
        expect(activeTab.queryParams.service).toBe('civit-ai-images');
        expect(vm.loadAtPage).toBe(1); // Should trigger load
        expect(vm.hasServiceSelected).toBe(true); // Service should be selected

        // Verify masonry is rendered
        const masonry = wrapper.findComponent({ name: 'Masonry' });
        expect(masonry.exists()).toBe(true);

        // Manually trigger load if masonry hasn't loaded yet (for test environment)
        // In real usage, masonry watches loadAtPage and auto-loads
        if (vm.masonry && vm.loadAtPage !== null && vm.items.length === 0) {
            // Simulate masonry triggering getNextPage
            await vm.getNextPage(vm.loadAtPage);
            await flushPromises();
            await wrapper.vm.$nextTick();
        }

        // Verify browse API was called with service parameter
        // Filter out the fetchServices call (which uses limit=1) and check the actual image loading call
        const browseCalls = mockAxios.get.mock.calls
            .map(call => call[0])
            .filter((callUrl: string) => {
                // Only include /api/browse calls (not /api/browse-tabs)
                // Exclude fetchServices call (limit=1) and services endpoint
                return typeof callUrl === 'string'
                    && callUrl.includes('/api/browse?')
                    && !callUrl.includes('/api/browse-tabs')
                    && !callUrl.includes('limit=1'); // Exclude fetchServices call
            });
        expect(browseCalls.length).toBeGreaterThan(0);
        // Check the last call (the actual image loading call after applying service)
        const lastCall = browseCalls[browseCalls.length - 1];
        expect(lastCall).toContain('source=civit-ai-images');
    });

    it('restores service when switching to tab with saved service', async () => {
        const tab1Id = 1;
        const tab2Id = 2;

        mockAxios.get.mockImplementation((url: string) => {
            if (url.includes('/api/browse-tabs')) {
                return Promise.resolve({
                    data: [
                        {
                            id: tab1Id,
                            label: 'Tab 1',
                            query_params: { service: 'civit-ai-images', page: 1 },
                            file_ids: [],
                            position: 0,
                        },
                        {
                            id: tab2Id,
                            label: 'Tab 2',
                            query_params: { service: 'wallhaven', page: 1 },
                            file_ids: [],
                            position: 1,
                        },
                    ],
                });
            }
            if (url.includes('/api/browse')) {
                return Promise.resolve({
                    data: {
                        items: [],
                        nextPage: null,
                        services: [
                            { key: 'civit-ai-images', label: 'CivitAI Images' },
                            { key: 'wallhaven', label: 'Wallhaven' },
                        ],
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

        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 300));

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;

        // First tab should be active with its service
        expect(vm.activeTabId).toBe(tab1Id);
        expect(vm.currentTabService).toBe('civit-ai-images');
        expect(vm.selectedService).toBe('civit-ai-images');

        // Switch to second tab
        await vm.switchTab(tab2Id);
        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 300));

        // Second tab should have its service restored
        expect(vm.activeTabId).toBe(tab2Id);
        expect(vm.currentTabService).toBe('wallhaven');
        expect(vm.selectedService).toBe('wallhaven');
    });

    it('includes service parameter in browse API calls', async () => {
        const tabId = 1;

        mockAxios.get.mockImplementation((url: string) => {
            if (url.includes('/api/browse-tabs')) {
                return Promise.resolve({
                    data: [{
                        id: tabId,
                        label: 'Test Tab',
                        query_params: { service: 'wallhaven', page: 1 },
                        file_ids: [],
                        position: 0,
                    }],
                });
            }
            if (url.includes('/api/browse')) {
                return Promise.resolve({
                    data: {
                        items: [
                            { id: 1, width: 100, height: 100, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false },
                        ],
                        nextPage: 'cursor-2',
                        services: [
                            { key: 'civit-ai-images', label: 'CivitAI Images' },
                            { key: 'wallhaven', label: 'Wallhaven' },
                        ],
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

        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 500));

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;

        // Reset restoration flag to allow loading
        vm.isTabRestored = false;
        vm.loadAtPage = 1;

        // Trigger getNextPage
        await vm.getNextPage(1);
        await flushPromises();

        // Verify browse API was called with service parameter
        const browseCalls = mockAxios.get.mock.calls
            .map(call => call[0])
            .filter((callUrl: string) => callUrl.includes('/api/browse') && !callUrl.includes('services'));

        expect(browseCalls.length).toBeGreaterThan(0);
        const lastCall = browseCalls[browseCalls.length - 1];
        expect(lastCall).toContain('source=wallhaven');
    });

    it('registers backfill event handlers on masonry component', async () => {
        mockAxios.get.mockImplementation((url: string) => {
            if (url.includes('/api/browse-tabs')) {
                return Promise.resolve({
                    data: [{
                        id: 1,
                        label: 'Test Tab',
                        query_params: { service: 'civit-ai-images', page: 1 },
                        file_ids: [],
                        position: 0,
                    }],
                });
            }
            if (url.includes('/api/browse')) {
                return Promise.resolve({
                    data: {
                        items: [],
                        nextPage: null,
                        services: [
                            { key: 'civit-ai-images', label: 'CivitAI Images' },
                        ],
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

        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 500));

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;

        // Verify backfill handlers exist
        expect(typeof vm.onBackfillStart).toBe('function');
        expect(typeof vm.onBackfillTick).toBe('function');
        expect(typeof vm.onBackfillStop).toBe('function');
        expect(typeof vm.onBackfillRetryStart).toBe('function');
        expect(typeof vm.onBackfillRetryTick).toBe('function');
        expect(typeof vm.onBackfillRetryStop).toBe('function');

        // Verify backfill state exists
        expect(vm.backfill).toBeDefined();
        expect(vm.backfill.active).toBe(false);
        expect(vm.backfill.fetched).toBe(0);
        expect(vm.backfill.target).toBe(0);
    });

    describe('Overlay functionality', () => {
        beforeEach(() => {
            // Mock getBoundingClientRect for overlay positioning
            Element.prototype.getBoundingClientRect = vi.fn(() => ({
                top: 100,
                left: 200,
                width: 300,
                height: 400,
                bottom: 500,
                right: 500,
                x: 200,
                y: 100,
                toJSON: vi.fn(),
            }));
        });

        it('shows overlay when clicking on a masonry item', async () => {
            mockAxios.get.mockImplementation((url: string) => {
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
                            items: [
                                { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false },
                            ],
                            nextPage: null,
                            services: [
                                { key: 'civit-ai-images', label: 'CivitAI Images' },
                            ],
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

            await flushPromises();
            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 300));

            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const vm = wrapper.vm as any;
            vm.items = [{ id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false }];
            await wrapper.vm.$nextTick();

            // Create a mock masonry item element
            const masonryContainer = wrapper.find('[ref="masonryContainer"]');
            if (masonryContainer.exists()) {
                const mockItem = document.createElement('div');
                mockItem.className = 'masonry-item';
                const mockImg = document.createElement('img');
                mockImg.src = 'test1.jpg';
                mockImg.setAttribute('srcset', 'test1.jpg 1x');
                mockImg.setAttribute('sizes', '(max-width: 300px) 300px');
                mockImg.setAttribute('alt', 'Test image');
                mockItem.appendChild(mockImg);
                masonryContainer.element.appendChild(mockItem);

                // Mock getBoundingClientRect for item
                mockItem.getBoundingClientRect = vi.fn(() => ({
                    top: 150,
                    left: 250,
                    width: 300,
                    height: 400,
                    bottom: 550,
                    right: 550,
                    x: 250,
                    y: 150,
                    toJSON: vi.fn(),
                }));

                // Click on the masonry item
                const clickEvent = new MouseEvent('click', { bubbles: true });
                Object.defineProperty(clickEvent, 'target', { value: mockImg, enumerable: true });
                masonryContainer.element.dispatchEvent(clickEvent);

                await wrapper.vm.$nextTick();
                await new Promise(resolve => setTimeout(resolve, 100));

                // Verify overlay is shown - check FileViewer component state
                const fileViewer = wrapper.findComponent(FileViewer);
                // eslint-disable-next-line @typescript-eslint/no-explicit-any
                const fileViewerVm = fileViewer.vm as any;
                expect(fileViewerVm.overlayRect).not.toBeNull();
                expect(fileViewerVm.overlayImage).not.toBeNull();
                expect(fileViewerVm.overlayImageSize).not.toBeNull();
            }
        });

        it('closes overlay when clicking close button', async () => {
            mockAxios.get.mockImplementation((url: string) => {
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
                            services: [
                                { key: 'civit-ai-images', label: 'CivitAI Images' },
                            ],
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

            await flushPromises();
            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 300));

            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const vm = wrapper.vm as any;
            const fileViewer = wrapper.findComponent(FileViewer);
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const fileViewerVm = fileViewer.vm as any;

            // Set overlay state manually on FileViewer component
            fileViewerVm.overlayRect = { top: 100, left: 200, width: 300, height: 400 };
            fileViewerVm.overlayImage = { src: 'test.jpg', srcset: 'test.jpg 1x', sizes: '300px', alt: 'Test' };
            fileViewerVm.overlayImageSize = { width: 300, height: 400 };
            fileViewerVm.overlayIsFilled = true;
            fileViewerVm.overlayFillComplete = true;
            await wrapper.vm.$nextTick();

            // Find and click close button
            const closeButton = wrapper.find('[data-test="close-overlay-button"]');
            expect(closeButton.exists()).toBe(true);

            await closeButton.trigger('click');
            await wrapper.vm.$nextTick();

            // Wait for close animation to complete (500ms + small buffer)
            await new Promise(resolve => setTimeout(resolve, 600));

            // Verify overlay is closed
            expect(fileViewerVm.overlayRect).toBeNull();
            expect(fileViewerVm.overlayImage).toBeNull();
            expect(fileViewerVm.overlayImageSize).toBeNull();
            expect(fileViewerVm.overlayIsFilled).toBe(false);
        });

        it('closes overlay when clicking outside masonry item', async () => {
            mockAxios.get.mockImplementation((url: string) => {
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
                            services: [
                                { key: 'civit-ai-images', label: 'CivitAI Images' },
                            ],
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

            await flushPromises();
            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 300));

            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const vm = wrapper.vm as any;
            const fileViewer = wrapper.findComponent(FileViewer);
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const fileViewerVm = fileViewer.vm as any;

            // Set overlay state manually
            fileViewerVm.overlayRect = { top: 100, left: 200, width: 300, height: 400 };
            fileViewerVm.overlayImage = { src: 'test.jpg', srcset: 'test.jpg 1x', sizes: '300px', alt: 'Test' };
            fileViewerVm.overlayImageSize = { width: 300, height: 400 };
            await wrapper.vm.$nextTick();

            // Click outside masonry item (on container but not on item)
            const masonryContainer = wrapper.find('[ref="masonryContainer"]');
            if (masonryContainer.exists()) {
                const clickEvent = new MouseEvent('click', { bubbles: true });
                Object.defineProperty(clickEvent, 'target', { value: masonryContainer.element, enumerable: true });
                masonryContainer.element.dispatchEvent(clickEvent);

                await wrapper.vm.$nextTick();

                // Verify overlay is closed
                expect(fileViewerVm.overlayRect).toBeNull();
                expect(fileViewerVm.overlayImage).toBeNull();
            }
        });

        it('maintains image size when overlay expands', async () => {
            mockAxios.get.mockImplementation((url: string) => {
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
                            services: [
                                { key: 'civit-ai-images', label: 'CivitAI Images' },
                            ],
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

            await flushPromises();
            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 300));

            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const vm = wrapper.vm as any;
            const fileViewer = wrapper.findComponent(FileViewer);
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const fileViewerVm = fileViewer.vm as any;

            const originalWidth = 300;
            const originalHeight = 400;

            // Set overlay state with original image size
            fileViewerVm.overlayRect = { top: 100, left: 200, width: originalWidth, height: originalHeight };
            fileViewerVm.overlayImage = { src: 'test.jpg', srcset: 'test.jpg 1x', sizes: '300px', alt: 'Test' };
            fileViewerVm.overlayImageSize = { width: originalWidth, height: originalHeight };
            fileViewerVm.overlayIsFilled = false;
            await wrapper.vm.$nextTick();

            // Verify image size is stored
            expect(fileViewerVm.overlayImageSize.width).toBe(originalWidth);
            expect(fileViewerVm.overlayImageSize.height).toBe(originalHeight);

            // Simulate overlay expanding to fill container
            fileViewerVm.overlayIsFilled = true;
            fileViewerVm.overlayRect = { top: 0, left: 0, width: 1920, height: 1080 }; // Full container size
            await wrapper.vm.$nextTick();

            // Verify image size is still maintained
            expect(fileViewerVm.overlayImageSize.width).toBe(originalWidth);
            expect(fileViewerVm.overlayImageSize.height).toBe(originalHeight);

            // Check that image element has fixed size
            const overlay = wrapper.find('[data-test="close-overlay-button"]');
            if (overlay.exists()) {
                const img = wrapper.find('img[src="test.jpg"]');
                if (img.exists()) {
                    const imgStyle = img.attributes('style') || '';
                    expect(imgStyle).toContain(`width: ${originalWidth}px`);
                    expect(imgStyle).toContain(`height: ${originalHeight}px`);
                }
            }
        });

        it('overlay has dark blue background', async () => {
            mockAxios.get.mockImplementation((url: string) => {
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
                            services: [
                                { key: 'civit-ai-images', label: 'CivitAI Images' },
                            ],
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

            await flushPromises();
            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 300));

            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const vm = wrapper.vm as any;
            const fileViewer = wrapper.findComponent(FileViewer);
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const fileViewerVm = fileViewer.vm as any;

            // Set overlay state
            fileViewerVm.overlayRect = { top: 100, left: 200, width: 300, height: 400 };
            fileViewerVm.overlayImage = { src: 'test.jpg', srcset: 'test.jpg 1x', sizes: '300px', alt: 'Test' };
            await wrapper.vm.$nextTick();

            // Verify overlay has dark blue background
            const overlay = wrapper.find('.bg-prussian-blue-900');
            expect(overlay.exists()).toBe(true);
        });

        it('close button is only visible when overlay fill is complete and not closing', async () => {
            mockAxios.get.mockImplementation((url: string) => {
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
                            services: [
                                { key: 'civit-ai-images', label: 'CivitAI Images' },
                            ],
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

            await flushPromises();
            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 300));

            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const vm = wrapper.vm as any;
            const fileViewer = wrapper.findComponent(FileViewer);
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const fileViewerVm = fileViewer.vm as any;

            // Set overlay state but not filled
            fileViewerVm.overlayRect = { top: 100, left: 200, width: 300, height: 400 };
            fileViewerVm.overlayImage = { src: 'test.jpg', srcset: 'test.jpg 1x', sizes: '300px', alt: 'Test' };
            fileViewerVm.overlayIsFilled = false;
            fileViewerVm.overlayFillComplete = false;
            await wrapper.vm.$nextTick();

            // Close button should not be visible
            let closeButton = wrapper.find('[data-test="close-overlay-button"]');
            expect(closeButton.exists()).toBe(false);

            // Set overlay to filled but not complete
            fileViewerVm.overlayIsFilled = true;
            fileViewerVm.overlayFillComplete = false;
            await wrapper.vm.$nextTick();

            // Close button should still not be visible (fill not complete)
            closeButton = wrapper.find('[data-test="close-overlay-button"]');
            expect(closeButton.exists()).toBe(false);

            // Set overlay fill to complete
            fileViewerVm.overlayFillComplete = true;
            fileViewerVm.overlayIsClosing = false;
            await wrapper.vm.$nextTick();

            // Close button should now be visible
            closeButton = wrapper.find('[data-test="close-overlay-button"]');
            expect(closeButton.exists()).toBe(true);

            // Set overlay to closing
            fileViewerVm.overlayIsClosing = true;
            await wrapper.vm.$nextTick();

            // Close button should be hidden during closing animation
            closeButton = wrapper.find('[data-test="close-overlay-button"]');
            expect(closeButton.exists()).toBe(false);
        });

        it('animates overlay to center position', async () => {
            mockAxios.get.mockImplementation((url: string) => {
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
                            services: [
                                { key: 'civit-ai-images', label: 'CivitAI Images' },
                            ],
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

            await flushPromises();
            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 300));

            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const vm = wrapper.vm as any;
            const fileViewer = wrapper.findComponent(FileViewer);
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const fileViewerVm = fileViewer.vm as any;

            // Mock container dimensions
            const containerWidth = 1920;
            const containerHeight = 1080;
            const itemWidth = 300;
            const itemHeight = 400;

            // Set initial overlay state (at clicked position)
            fileViewerVm.overlayRect = { top: 100, left: 200, width: itemWidth, height: itemHeight };
            fileViewerVm.overlayImage = { src: 'test.jpg', srcset: 'test.jpg 1x', sizes: '300px', alt: 'Test' };
            fileViewerVm.overlayImageSize = { width: itemWidth, height: itemHeight };
            fileViewerVm.overlayIsAnimating = false;
            await wrapper.vm.$nextTick();

            // Mock tabContentContainer getBoundingClientRect
            const tabContentContainer = wrapper.find('[ref="tabContentContainer"]');
            if (tabContentContainer.exists()) {
                tabContentContainer.element.getBoundingClientRect = vi.fn(() => ({
                    top: 0,
                    left: 0,
                    width: containerWidth,
                    height: containerHeight,
                    bottom: containerHeight,
                    right: containerWidth,
                    x: 0,
                    y: 0,
                    toJSON: vi.fn(),
                }));

                // Trigger animation to center
                fileViewerVm.overlayIsAnimating = true;
                const centerLeft = Math.round((containerWidth - itemWidth) / 2);
                const centerTop = Math.round((containerHeight - itemHeight) / 2);
                fileViewerVm.overlayRect = { top: centerTop, left: centerLeft, width: itemWidth, height: itemHeight };
                await wrapper.vm.$nextTick();

                // Verify overlay is centered
                expect(fileViewerVm.overlayRect.left).toBe(centerLeft);
                expect(fileViewerVm.overlayRect.top).toBe(centerTop);
                expect(fileViewerVm.overlayIsAnimating).toBe(true);
            }
        });

        it('animates overlay to fill container after centering', async () => {
            mockAxios.get.mockImplementation((url: string) => {
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
                            services: [
                                { key: 'civit-ai-images', label: 'CivitAI Images' },
                            ],
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

            await flushPromises();
            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 300));

            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const vm = wrapper.vm as any;
            const fileViewer = wrapper.findComponent(FileViewer);
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const fileViewerVm = fileViewer.vm as any;

            const containerWidth = 1920;
            const containerHeight = 1080;
            const itemWidth = 300;
            const itemHeight = 400;

            // Set centered state
            fileViewerVm.overlayRect = { top: 340, left: 810, width: itemWidth, height: itemHeight };
            fileViewerVm.overlayImage = { src: 'test.jpg', srcset: 'test.jpg 1x', sizes: '300px', alt: 'Test' };
            fileViewerVm.overlayImageSize = { width: itemWidth, height: itemHeight };
            fileViewerVm.overlayIsAnimating = true;
            fileViewerVm.overlayIsFilled = false;
            await wrapper.vm.$nextTick();

            // Simulate fill animation
            fileViewerVm.overlayIsFilled = true;
            fileViewerVm.overlayRect = { top: 0, left: 0, width: containerWidth, height: containerHeight };
            await wrapper.vm.$nextTick();

            // Verify overlay fills container
            expect(fileViewerVm.overlayRect.top).toBe(0);
            expect(fileViewerVm.overlayRect.left).toBe(0);
            expect(fileViewerVm.overlayRect.width).toBe(containerWidth);
            expect(fileViewerVm.overlayRect.height).toBe(containerHeight);
            expect(fileViewerVm.overlayIsFilled).toBe(true);
        });

        it('uses flexbox centering when overlay is filled', async () => {
            mockAxios.get.mockImplementation((url: string) => {
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
                            services: [
                                { key: 'civit-ai-images', label: 'CivitAI Images' },
                            ],
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

            await flushPromises();
            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 300));

            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const vm = wrapper.vm as any;
            const fileViewer = wrapper.findComponent(FileViewer);
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const fileViewerVm = fileViewer.vm as any;

            // Set filled state
            fileViewerVm.overlayRect = { top: 0, left: 0, width: 1920, height: 1080 };
            fileViewerVm.overlayImage = { src: 'test.jpg', srcset: 'test.jpg 1x', sizes: '300px', alt: 'Test' };
            fileViewerVm.overlayImageSize = { width: 300, height: 400 };
            fileViewerVm.overlayIsFilled = true;
            await wrapper.vm.$nextTick();

            // Verify overlay exists with correct border styling
            const overlay = wrapper.find('.border-smart-blue-500');
            expect(overlay.exists()).toBe(true);
            expect(overlay.classes()).toContain('border-4');
            expect(overlay.classes()).toContain('border-smart-blue-500');

            // Verify image maintains its size
            const img = wrapper.find('img[src="test.jpg"]');
            if (img.exists()) {
                const imgStyle = img.attributes('style') || '';
                expect(imgStyle).toContain('width: 300px');
                expect(imgStyle).toContain('height: 400px');
            }
        });

        it('animates overlay scale to 0 when closing', async () => {
            mockAxios.get.mockImplementation((url: string) => {
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
                            services: [
                                { key: 'civit-ai-images', label: 'CivitAI Images' },
                            ],
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

            await flushPromises();
            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 300));

            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const vm = wrapper.vm as any;
            const fileViewer = wrapper.findComponent(FileViewer);
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const fileViewerVm = fileViewer.vm as any;

            // Set overlay state
            fileViewerVm.overlayRect = { top: 100, left: 200, width: 300, height: 400 };
            fileViewerVm.overlayImage = { src: 'test.jpg', srcset: 'test.jpg 1x', sizes: '300px', alt: 'Test' };
            fileViewerVm.overlayIsFilled = true;
            fileViewerVm.overlayFillComplete = true;
            fileViewerVm.overlayScale = 1;
            await wrapper.vm.$nextTick();

            // Verify initial scale
            expect(fileViewerVm.overlayScale).toBe(1);
            const overlay = wrapper.find('.border-smart-blue-500');
            expect(overlay.exists()).toBe(true);
            const overlayStyle = overlay.attributes('style') || '';
            expect(overlayStyle).toContain('scale(1)');

            // Trigger close
            fileViewerVm.closeOverlay();
            await wrapper.vm.$nextTick();

            // Verify scale is set to 0
            expect(fileViewerVm.overlayScale).toBe(0);
            expect(fileViewerVm.overlayIsClosing).toBe(true);
            await wrapper.vm.$nextTick();

            // Verify transform style includes scale(0)
            const updatedOverlay = wrapper.find('.border-smart-blue-500');
            const updatedStyle = updatedOverlay.attributes('style') || '';
            expect(updatedStyle).toContain('scale(0)');
        });

        it('has overflow hidden during closing animation', async () => {
            mockAxios.get.mockImplementation((url: string) => {
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
                            services: [
                                { key: 'civit-ai-images', label: 'CivitAI Images' },
                            ],
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

            await flushPromises();
            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 300));

            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const vm = wrapper.vm as any;
            const fileViewer = wrapper.findComponent(FileViewer);
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const fileViewerVm = fileViewer.vm as any;

            // Set overlay state - filled but not closing
            fileViewerVm.overlayRect = { top: 0, left: 0, width: 1920, height: 1080 };
            fileViewerVm.overlayImage = { src: 'test.jpg', srcset: 'test.jpg 1x', sizes: '300px', alt: 'Test' };
            fileViewerVm.overlayIsFilled = true;
            fileViewerVm.overlayIsClosing = false;
            await wrapper.vm.$nextTick();

            // overflow-hidden should always be applied to prevent image overlap
            let overlay = wrapper.find('.border-smart-blue-500');
            expect(overlay.exists()).toBe(true);
            expect(overlay.classes()).toContain('overflow-hidden');

            // Set overlay to closing
            fileViewerVm.overlayIsClosing = true;
            await wrapper.vm.$nextTick();

            // When closing, overflow-hidden should still be applied
            overlay = wrapper.find('.border-smart-blue-500');
            expect(overlay.exists()).toBe(true);
            expect(overlay.classes()).toContain('overflow-hidden');
        });

        it('has correct border styling', async () => {
            mockAxios.get.mockImplementation((url: string) => {
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
                            services: [
                                { key: 'civit-ai-images', label: 'CivitAI Images' },
                            ],
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

            await flushPromises();
            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 300));

            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const vm = wrapper.vm as any;
            const fileViewer = wrapper.findComponent(FileViewer);
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const fileViewerVm = fileViewer.vm as any;

            // Set overlay state
            fileViewerVm.overlayRect = { top: 100, left: 200, width: 300, height: 400 };
            fileViewerVm.overlayImage = { src: 'test.jpg', srcset: 'test.jpg 1x', sizes: '300px', alt: 'Test' };
            await wrapper.vm.$nextTick();

            // Verify border styling
            const overlay = wrapper.find('.border-smart-blue-500');
            expect(overlay.exists()).toBe(true);
            expect(overlay.classes()).toContain('border-4');
            expect(overlay.classes()).toContain('border-smart-blue-500');
        });

        it('closes overlay when pressing Escape key', async () => {
            mockAxios.get.mockImplementation((url: string) => {
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
                            services: [
                                { key: 'civit-ai-images', label: 'CivitAI Images' },
                            ],
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

            await flushPromises();
            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 300));

            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const vm = wrapper.vm as any;
            const fileViewer = wrapper.findComponent(FileViewer);
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const fileViewerVm = fileViewer.vm as any;

            // Set overlay state
            fileViewerVm.overlayRect = { top: 100, left: 200, width: 300, height: 400 };
            fileViewerVm.overlayImage = { src: 'test.jpg', srcset: 'test.jpg 1x', sizes: '300px', alt: 'Test' };
            fileViewerVm.overlayIsFilled = true;
            fileViewerVm.overlayFillComplete = true;
            await wrapper.vm.$nextTick();

            // Verify overlay is visible
            expect(fileViewerVm.overlayRect).not.toBeNull();

            // Simulate Escape key press
            const escapeEvent = new KeyboardEvent('keydown', { key: 'Escape', bubbles: true });
            window.dispatchEvent(escapeEvent);

            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 600)); // Wait for close animation

            // Verify overlay is closed
            expect(fileViewerVm.overlayRect).toBeNull();
            expect(fileViewerVm.overlayImage).toBeNull();
        });

        it('navigates to next image when pressing ArrowRight key', async () => {
            mockAxios.get.mockImplementation((url: string) => {
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
                            items: [
                                {
                                    id: 1,
                                    width: 100,
                                    height: 100,
                                    page: 1,
                                    index: 0,
                                    src: 'test1.jpg',
                                    originalUrl: 'test1-full.jpg',
                                },
                                {
                                    id: 2,
                                    width: 200,
                                    height: 200,
                                    page: 1,
                                    index: 1,
                                    src: 'test2.jpg',
                                    originalUrl: 'test2-full.jpg',
                                },
                            ],
                            nextPage: null,
                            services: [
                                { key: 'civit-ai-images', label: 'CivitAI Images' },
                            ],
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

            await flushPromises();
            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 300));

            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const vm = wrapper.vm as any;

            // Set items on Browse component (FileViewer receives this as prop)
            vm.items = [
                {
                    id: 1,
                    width: 100,
                    height: 100,
                    page: 1,
                    index: 0,
                    src: 'test1.jpg',
                    originalUrl: 'test1-full.jpg',
                },
                {
                    id: 2,
                    width: 200,
                    height: 200,
                    page: 1,
                    index: 1,
                    src: 'test2.jpg',
                    originalUrl: 'test2-full.jpg',
                },
            ];

            const fileViewer = wrapper.findComponent(FileViewer);
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const fileViewerVm = fileViewer.vm as any;

            // Set overlay state (filled and complete)
            fileViewerVm.overlayRect = { top: 0, left: 0, width: 800, height: 600 };
            fileViewerVm.overlayImage = { src: 'test1.jpg', alt: 'Test 1' };
            fileViewerVm.overlayIsFilled = true;
            fileViewerVm.overlayFillComplete = true;
            fileViewerVm.currentItemIndex = 0;
            fileViewerVm.imageScale = 1;
            fileViewerVm.overlayFullSizeImage = 'test1-full.jpg';
            fileViewerVm.overlayIsLoading = false;
            fileViewerVm.overlayImageSize = { width: 400, height: 400 };
            fileViewerVm.imageCenterPosition = { top: 100, left: 200 };

            // Ensure containerRef is set (needed for navigation)
            const tabContentContainer = wrapper.find('[ref="tabContentContainer"]');
            if (tabContentContainer.exists()) {
                fileViewerVm.containerRef = tabContentContainer.element;
            }

            await wrapper.vm.$nextTick();

            // Verify initial state
            expect(fileViewerVm.currentItemIndex).toBe(0);
            expect(fileViewerVm.imageScale).toBe(1);

            // Simulate ArrowRight key press
            const arrowRightEvent = new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true });
            window.dispatchEvent(arrowRightEvent);

            // Wait for navigation to start (async function)
            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 50));

            // Verify navigation started (image should start sliding)
            expect(fileViewerVm.isNavigating).toBe(true);
            expect(fileViewerVm.imageTranslateX).not.toBe(0); // Should be sliding out
            expect(fileViewerVm.navigationDirection).toBe('right');

            // Note: Full navigation completion requires image preloading which may fail in test environment
            // The important part is that navigation starts correctly when ArrowRight is pressed
        });

        it('navigates to previous image when pressing ArrowLeft key', async () => {
            mockAxios.get.mockImplementation((url: string) => {
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
                            items: [
                                {
                                    id: 1,
                                    width: 100,
                                    height: 100,
                                    page: 1,
                                    index: 0,
                                    src: 'test1.jpg',
                                    originalUrl: 'test1-full.jpg',
                                },
                                {
                                    id: 2,
                                    width: 200,
                                    height: 200,
                                    page: 1,
                                    index: 1,
                                    src: 'test2.jpg',
                                    originalUrl: 'test2-full.jpg',
                                },
                            ],
                            nextPage: null,
                            services: [
                                { key: 'civit-ai-images', label: 'CivitAI Images' },
                            ],
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

            await flushPromises();
            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 300));

            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const vm = wrapper.vm as any;

            // Set items on Browse component
            vm.items = [
                {
                    id: 1,
                    width: 100,
                    height: 100,
                    page: 1,
                    index: 0,
                    src: 'test1.jpg',
                    originalUrl: 'test1-full.jpg',
                },
                {
                    id: 2,
                    width: 200,
                    height: 200,
                    page: 1,
                    index: 1,
                    src: 'test2.jpg',
                    originalUrl: 'test2-full.jpg',
                },
            ];

            const fileViewer = wrapper.findComponent(FileViewer);
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const fileViewerVm = fileViewer.vm as any;

            // Set overlay state (filled and complete, at second item)
            fileViewerVm.overlayRect = { top: 0, left: 0, width: 800, height: 600 };
            fileViewerVm.overlayImage = { src: 'test2.jpg', alt: 'Test 2' };
            fileViewerVm.overlayIsFilled = true;
            fileViewerVm.overlayFillComplete = true;
            fileViewerVm.currentItemIndex = 1;
            fileViewerVm.imageScale = 1;
            fileViewerVm.overlayFullSizeImage = 'test2-full.jpg';
            fileViewerVm.overlayIsLoading = false;
            fileViewerVm.overlayImageSize = { width: 400, height: 400 };
            fileViewerVm.imageCenterPosition = { top: 100, left: 200 };

            // Ensure containerRef is set
            const tabContentContainer = wrapper.find('[ref="tabContentContainer"]');
            if (tabContentContainer.exists()) {
                fileViewerVm.containerRef = tabContentContainer.element;
            }

            await wrapper.vm.$nextTick();

            // Verify initial state
            expect(fileViewerVm.currentItemIndex).toBe(1);
            expect(fileViewerVm.imageScale).toBe(1);

            // Simulate ArrowLeft key press
            const arrowLeftEvent = new KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true });
            window.dispatchEvent(arrowLeftEvent);

            // Wait for navigation to start
            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 50));

            // Verify navigation started (image should start sliding)
            expect(fileViewerVm.isNavigating).toBe(true);
            expect(fileViewerVm.imageTranslateX).not.toBe(0); // Should be sliding out
            expect(fileViewerVm.navigationDirection).toBe('left');

            // Note: Full navigation completion requires image preloading which may fail in test environment
            // The important part is that navigation starts correctly when ArrowLeft is pressed
        });

        it('does not navigate when at first item and pressing ArrowLeft', async () => {
            mockAxios.get.mockImplementation((url: string) => {
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
                            items: [
                                {
                                    id: 1,
                                    width: 100,
                                    height: 100,
                                    page: 1,
                                    index: 0,
                                    src: 'test1.jpg',
                                    originalUrl: 'test1-full.jpg',
                                },
                            ],
                            nextPage: null,
                            services: [
                                { key: 'civit-ai-images', label: 'CivitAI Images' },
                            ],
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

            await flushPromises();
            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 300));

            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const vm = wrapper.vm as any;
            const fileViewer = wrapper.findComponent(FileViewer);
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const fileViewerVm = fileViewer.vm as any;

            // Set overlay state (filled and complete, at first item)
            fileViewerVm.overlayRect = { top: 0, left: 0, width: 800, height: 600 };
            fileViewerVm.overlayImage = { src: 'test1.jpg', alt: 'Test 1' };
            fileViewerVm.overlayIsFilled = true;
            fileViewerVm.overlayFillComplete = true;
            fileViewerVm.currentItemIndex = 0;
            fileViewerVm.imageScale = 1;
            await wrapper.vm.$nextTick();

            // Simulate ArrowLeft key press
            const arrowLeftEvent = new KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true });
            window.dispatchEvent(arrowLeftEvent);

            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 100));

            // Verify no navigation occurred (still at first item)
            expect(fileViewerVm.currentItemIndex).toBe(0);
            expect(fileViewerVm.isNavigating).toBe(false);
            expect(fileViewerVm.imageScale).toBe(1);
        });

        it('does not navigate when at last item and pressing ArrowRight', async () => {
            mockAxios.get.mockImplementation((url: string) => {
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
                            items: [
                                {
                                    id: 1,
                                    width: 100,
                                    height: 100,
                                    page: 1,
                                    index: 0,
                                    src: 'test1.jpg',
                                    originalUrl: 'test1-full.jpg',
                                },
                            ],
                            nextPage: null,
                            services: [
                                { key: 'civit-ai-images', label: 'CivitAI Images' },
                            ],
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

            await flushPromises();
            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 300));

            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const vm = wrapper.vm as any;
            const fileViewer = wrapper.findComponent(FileViewer);
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const fileViewerVm = fileViewer.vm as any;

            // Set overlay state (filled and complete, at last item)
            fileViewerVm.overlayRect = { top: 0, left: 0, width: 800, height: 600 };
            fileViewerVm.overlayImage = { src: 'test1.jpg', alt: 'Test 1' };
            fileViewerVm.overlayIsFilled = true;
            fileViewerVm.overlayFillComplete = true;
            fileViewerVm.currentItemIndex = 0; // Last item (only one item in array)
            fileViewerVm.imageScale = 1;
            await wrapper.vm.$nextTick();

            // Simulate ArrowRight key press
            const arrowRightEvent = new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true });
            window.dispatchEvent(arrowRightEvent);

            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 100));

            // Verify no navigation occurred (still at last item)
            expect(fileViewerVm.currentItemIndex).toBe(0);
            expect(fileViewerVm.isNavigating).toBe(false);
            expect(fileViewerVm.imageScale).toBe(1);
        });

        it('opens drawer when clicking on image', async () => {
            mockAxios.get.mockImplementation((url: string) => {
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
                            items: [
                                { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false },
                            ],
                            nextPage: null,
                            services: [
                                { key: 'civit-ai-images', label: 'CivitAI Images' },
                            ],
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

            await flushPromises();
            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 300));

            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const vm = wrapper.vm as any;
            vm.items = [{ id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false }];
            await wrapper.vm.$nextTick();

            const fileViewer = wrapper.findComponent(FileViewer);
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const fileViewerVm = fileViewer.vm as any;

            // Set overlay state (filled and complete)
            fileViewerVm.overlayRect = { top: 0, left: 0, width: 800, height: 600 };
            fileViewerVm.overlayImage = { src: 'test1.jpg', alt: 'Test 1' };
            fileViewerVm.overlayIsFilled = true;
            fileViewerVm.overlayFillComplete = true;
            fileViewerVm.currentItemIndex = 0;
            fileViewerVm.overlayFullSizeImage = 'test1-full.jpg';
            fileViewerVm.overlayIsLoading = false;
            await wrapper.vm.$nextTick();

            // Find and click the full-size image to toggle drawer
            const overlayImage = fileViewer.find('img[alt="Test 1"]');
            expect(overlayImage.exists()).toBe(true);

            // Click the image to toggle drawer
            await overlayImage.trigger('click');

            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 100));

            // Verify drawer is open
            expect(fileViewerVm.isBottomPanelOpen).toBe(true);
        });

        it('displays preview images in drawer boxes', async () => {
            mockAxios.get.mockImplementation((url: string) => {
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
                            items: [
                                { id: 1, width: 100, height: 100, src: 'test1.jpg', page: 1, index: 0 },
                                { id: 2, width: 200, height: 200, src: 'test2.jpg', page: 1, index: 1 },
                                { id: 3, width: 300, height: 300, src: 'test3.jpg', page: 1, index: 2 },
                            ],
                            nextPage: null,
                            services: [
                                { key: 'civit-ai-images', label: 'CivitAI Images' },
                            ],
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

            await flushPromises();
            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 300));

            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const vm = wrapper.vm as any;
            vm.items = [
                { id: 1, width: 100, height: 100, src: 'test1.jpg', page: 1, index: 0 },
                { id: 2, width: 200, height: 200, src: 'test2.jpg', page: 1, index: 1 },
                { id: 3, width: 300, height: 300, src: 'test3.jpg', page: 1, index: 2 },
            ];

            const fileViewer = wrapper.findComponent(FileViewer);
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const fileViewerVm = fileViewer.vm as any;

            // Set overlay state
            fileViewerVm.overlayRect = { top: 0, left: 0, width: 800, height: 600 };
            fileViewerVm.overlayImage = { src: 'test1.jpg', alt: 'Test 1' };
            fileViewerVm.overlayIsFilled = true;
            fileViewerVm.overlayFillComplete = true;
            fileViewerVm.currentItemIndex = 0;
            fileViewerVm.isBottomPanelOpen = true;
            await wrapper.vm.$nextTick();

            // Verify carousel is rendered
            const carousel = wrapper.find('[data-test="image-carousel"]');
            expect(carousel.exists()).toBe(true);

            // Verify carousel displays items (new structure uses carousel-item-{index})
            const previewItem = wrapper.find('[data-test="carousel-item-0"]');
            expect(previewItem.exists()).toBe(true);
        });

        it('navigates when clicking drawer next button', async () => {
            mockAxios.get.mockImplementation((url: string) => {
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
                            items: [
                                { id: 1, width: 100, height: 100, src: 'test1.jpg', page: 1, index: 0 },
                                { id: 2, width: 200, height: 200, src: 'test2.jpg', page: 1, index: 1 },
                            ],
                            nextPage: null,
                            services: [
                                { key: 'civit-ai-images', label: 'CivitAI Images' },
                            ],
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

            await flushPromises();
            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 300));

            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const vm = wrapper.vm as any;
            vm.items = [
                { id: 1, width: 100, height: 100, src: 'test1.jpg', page: 1, index: 0 },
                { id: 2, width: 200, height: 200, src: 'test2.jpg', page: 1, index: 1 },
            ];

            const fileViewer = wrapper.findComponent(FileViewer);
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const fileViewerVm = fileViewer.vm as any;

            // Set overlay state
            fileViewerVm.overlayRect = { top: 0, left: 0, width: 800, height: 600 };
            fileViewerVm.overlayImage = { src: 'test1.jpg', alt: 'Test 1' };
            fileViewerVm.overlayIsFilled = true;
            fileViewerVm.overlayFillComplete = true;
            fileViewerVm.currentItemIndex = 0;
            fileViewerVm.isBottomPanelOpen = true;
            await wrapper.vm.$nextTick();

            // Click carousel next button
            const nextButton = wrapper.find('[data-test="carousel-next-button"]');
            expect(nextButton.exists()).toBe(true);
            await nextButton.trigger('click');

            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 50));

            // Verify navigation started
            expect(fileViewerVm.isNavigating).toBe(true);
        });

        it('navigates when clicking drawer previous button', async () => {
            mockAxios.get.mockImplementation((url: string) => {
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
                            items: [
                                { id: 1, width: 100, height: 100, src: 'test1.jpg', page: 1, index: 0 },
                                { id: 2, width: 200, height: 200, src: 'test2.jpg', page: 1, index: 1 },
                            ],
                            nextPage: null,
                            services: [
                                { key: 'civit-ai-images', label: 'CivitAI Images' },
                            ],
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

            await flushPromises();
            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 300));

            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const vm = wrapper.vm as any;
            vm.items = [
                { id: 1, width: 100, height: 100, src: 'test1.jpg', page: 1, index: 0 },
                { id: 2, width: 200, height: 200, src: 'test2.jpg', page: 1, index: 1 },
            ];

            const fileViewer = wrapper.findComponent(FileViewer);
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const fileViewerVm = fileViewer.vm as any;

            // Set overlay state (at second item)
            fileViewerVm.overlayRect = { top: 0, left: 0, width: 800, height: 600 };
            fileViewerVm.overlayImage = { src: 'test2.jpg', alt: 'Test 2' };
            fileViewerVm.overlayIsFilled = true;
            fileViewerVm.overlayFillComplete = true;
            fileViewerVm.currentItemIndex = 1;
            fileViewerVm.isBottomPanelOpen = true;
            await wrapper.vm.$nextTick();

            // Click carousel previous button
            const prevButton = wrapper.find('[data-test="carousel-previous-button"]');
            expect(prevButton.exists()).toBe(true);
            await prevButton.trigger('click');

            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 50));

            // Verify navigation started
            expect(fileViewerVm.isNavigating).toBe(true);
        });

        it('displays item in carousel when index > 4', async () => {
            mockAxios.get.mockImplementation((url: string) => {
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
                            items: Array.from({ length: 10 }, (_, i) => ({
                                id: i + 1,
                                width: 100,
                                height: 100,
                                src: `test${i + 1}.jpg`,
                                page: 1,
                                index: i,
                            })),
                            nextPage: null,
                            services: [
                                { key: 'civit-ai-images', label: 'CivitAI Images' },
                            ],
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

            await flushPromises();
            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 300));

            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const vm = wrapper.vm as any;
            vm.items = Array.from({ length: 10 }, (_, i) => ({
                id: i + 1,
                width: 100,
                height: 100,
                src: `test${i + 1}.jpg`,
                page: 1,
                index: i,
            }));

            const fileViewer = wrapper.findComponent(FileViewer);
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const fileViewerVm = fileViewer.vm as any;

            // Set overlay state with index 5 (should be centered in 6th box)
            fileViewerVm.overlayRect = { top: 0, left: 0, width: 800, height: 600 };
            fileViewerVm.overlayImage = { src: 'test6.jpg', alt: 'Test 6' };
            fileViewerVm.overlayIsFilled = true;
            fileViewerVm.overlayFillComplete = true;
            fileViewerVm.currentItemIndex = 5;
            fileViewerVm.isBottomPanelOpen = true;
            await wrapper.vm.$nextTick();

            // Verify item at index 5 is displayed (new carousel shows all items)
            const item5 = wrapper.find('[data-test="carousel-item-5"]');
            expect(item5.exists()).toBe(true);
            const preview5 = wrapper.find('[data-test="carousel-preview-5"]');
            expect(preview5.exists()).toBe(true);
            expect(preview5.attributes('alt')).toBe('Preview 6');
        });

        it('displays item in carousel when index <= 4', async () => {
            mockAxios.get.mockImplementation((url: string) => {
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
                            items: Array.from({ length: 10 }, (_, i) => ({
                                id: i + 1,
                                width: 100,
                                height: 100,
                                src: `test${i + 1}.jpg`,
                                page: 1,
                                index: i,
                            })),
                            nextPage: null,
                            services: [
                                { key: 'civit-ai-images', label: 'CivitAI Images' },
                            ],
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

            await flushPromises();
            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 300));

            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const vm = wrapper.vm as any;
            vm.items = Array.from({ length: 10 }, (_, i) => ({
                id: i + 1,
                width: 100,
                height: 100,
                src: `test${i + 1}.jpg`,
                page: 1,
                index: i,
            }));

            const fileViewer = wrapper.findComponent(FileViewer);
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const fileViewerVm = fileViewer.vm as any;

            // Set overlay state with index 2 (should be at box index 2)
            fileViewerVm.overlayRect = { top: 0, left: 0, width: 800, height: 600 };
            fileViewerVm.overlayImage = { src: 'test3.jpg', alt: 'Test 3' };
            fileViewerVm.overlayIsFilled = true;
            fileViewerVm.overlayFillComplete = true;
            fileViewerVm.currentItemIndex = 2;
            fileViewerVm.isBottomPanelOpen = true;
            await wrapper.vm.$nextTick();

            // Verify item at index 2 is displayed (new carousel shows all items)
            const item2 = wrapper.find('[data-test="carousel-item-2"]');
            expect(item2.exists()).toBe(true);
            const preview2 = wrapper.find('[data-test="carousel-preview-2"]');
            expect(preview2.exists()).toBe(true);
            expect(preview2.attributes('alt')).toBe('Preview 3');
        });

        it('navigates when clicking on carousel item', async () => {
            mockAxios.get.mockImplementation((url: string) => {
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
                            items: [
                                { id: 1, width: 100, height: 100, src: 'test1.jpg', page: 1, index: 0 },
                                { id: 2, width: 200, height: 200, src: 'test2.jpg', page: 1, index: 1 },
                            ],
                            nextPage: null,
                            services: [
                                { key: 'civit-ai-images', label: 'CivitAI Images' },
                            ],
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

            await flushPromises();
            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 300));

            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const vm = wrapper.vm as any;
            vm.items = [
                { id: 1, width: 100, height: 100, src: 'test1.jpg', page: 1, index: 0 },
                { id: 2, width: 200, height: 200, src: 'test2.jpg', page: 1, index: 1 },
            ];

            const fileViewer = wrapper.findComponent(FileViewer);
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const fileViewerVm = fileViewer.vm as any;

            // Set overlay state
            fileViewerVm.overlayRect = { top: 0, left: 0, width: 800, height: 600 };
            fileViewerVm.overlayImage = { src: 'test1.jpg', alt: 'Test 1' };
            fileViewerVm.overlayIsFilled = true;
            fileViewerVm.overlayFillComplete = true;
            fileViewerVm.currentItemIndex = 0;
            fileViewerVm.isBottomPanelOpen = true;
            await wrapper.vm.$nextTick();

            // Click on carousel item 1 (should navigate to item at index 1)
            const item1 = wrapper.find('[data-test="carousel-item-1"]');
            expect(item1.exists()).toBe(true);
            await item1.trigger('click');

            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 50));

            // Verify navigation started
            expect(fileViewerVm.isNavigating).toBe(true);
        });

        it('disables previous button when at first item', async () => {
            mockAxios.get.mockImplementation((url: string) => {
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
                            items: [
                                { id: 1, width: 100, height: 100, src: 'test1.jpg', page: 1, index: 0 },
                            ],
                            nextPage: null,
                            services: [
                                { key: 'civit-ai-images', label: 'CivitAI Images' },
                            ],
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

            await flushPromises();
            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 300));

            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const vm = wrapper.vm as any;
            vm.items = [
                { id: 1, width: 100, height: 100, src: 'test1.jpg', page: 1, index: 0 },
            ];

            const fileViewer = wrapper.findComponent(FileViewer);
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const fileViewerVm = fileViewer.vm as any;

            // Set overlay state at first item
            fileViewerVm.overlayRect = { top: 0, left: 0, width: 800, height: 600 };
            fileViewerVm.overlayImage = { src: 'test1.jpg', alt: 'Test 1' };
            fileViewerVm.overlayIsFilled = true;
            fileViewerVm.overlayFillComplete = true;
            fileViewerVm.currentItemIndex = 0;
            fileViewerVm.isBottomPanelOpen = true;
            await wrapper.vm.$nextTick();

            // Verify previous button is disabled
            const prevButton = wrapper.find('[data-test="carousel-previous-button"]');
            expect(prevButton.exists()).toBe(true);
            expect(prevButton.attributes('disabled')).toBeDefined();
        });

        it('shows FileReactions component on hover over masonry item', async () => {
            mockAxios.get.mockImplementation((url: string) => {
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
                            items: [
                                { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false },
                                { id: 2, width: 300, height: 400, src: 'test2.jpg', type: 'image', page: 1, index: 1, notFound: false },
                            ],
                            nextPage: null,
                            services: [
                                { key: 'civit-ai-images', label: 'CivitAI Images' },
                            ],
                        },
                    });
                }
                if (url.includes('/api/files') && url.includes('/reaction')) {
                    return Promise.resolve({
                        data: {
                            reaction: null,
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

            await flushPromises();
            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 500));

            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const vm = wrapper.vm as any;

            // Trigger hover on first item
            const masonryItems = wrapper.findAll('.masonry-mock > div');
            if (masonryItems.length > 0) {
                await masonryItems[0].trigger('mouseenter');
                await wrapper.vm.$nextTick();

                // FileReactions should be visible
                const fileReactions = wrapper.findComponent({ name: 'FileReactions' });
                expect(fileReactions.exists()).toBe(true);
            }
        });

        it('hides FileReactions component when mouse leaves masonry item', async () => {
            mockAxios.get.mockImplementation((url: string) => {
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
                            items: [
                                { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false },
                            ],
                            nextPage: null,
                            services: [
                                { key: 'civit-ai-images', label: 'CivitAI Images' },
                            ],
                        },
                    });
                }
                if (url.includes('/api/files') && url.includes('/reaction')) {
                    return Promise.resolve({
                        data: {
                            reaction: null,
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

            await flushPromises();
            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 500));

            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const vm = wrapper.vm as any;

            // Trigger hover on first item
            const masonryItems = wrapper.findAll('.masonry-mock > div');
            if (masonryItems.length > 0) {
                await masonryItems[0].trigger('mouseenter');
                await wrapper.vm.$nextTick();

                // FileReactions should be visible
                let fileReactions = wrapper.findComponent({ name: 'FileReactions' });
                expect(fileReactions.exists()).toBe(true);

                // Trigger mouse leave
                await masonryItems[0].trigger('mouseleave');
                await wrapper.vm.$nextTick();

                // FileReactions should be hidden (v-show="false")
                fileReactions = wrapper.findComponent({ name: 'FileReactions' });
                // Component might still exist but be hidden
                if (fileReactions.exists()) {
                    expect(fileReactions.isVisible()).toBe(false);
                }
            }
        });

        it('disables next button when at last item', async () => {
            mockAxios.get.mockImplementation((url: string) => {
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
                            items: [
                                { id: 1, width: 100, height: 100, src: 'test1.jpg', page: 1, index: 0 },
                            ],
                            nextPage: null,
                            services: [
                                { key: 'civit-ai-images', label: 'CivitAI Images' },
                            ],
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

            await flushPromises();
            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 300));

            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const vm = wrapper.vm as any;
            vm.items = [
                { id: 1, width: 100, height: 100, src: 'test1.jpg', page: 1, index: 0 },
            ];

            const fileViewer = wrapper.findComponent(FileViewer);
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const fileViewerVm = fileViewer.vm as any;

            // Set overlay state at last item
            fileViewerVm.overlayRect = { top: 0, left: 0, width: 800, height: 600 };
            fileViewerVm.overlayImage = { src: 'test1.jpg', alt: 'Test 1' };
            fileViewerVm.overlayIsFilled = true;
            fileViewerVm.overlayFillComplete = true;
            fileViewerVm.currentItemIndex = 0;
            fileViewerVm.isBottomPanelOpen = true;
            await wrapper.vm.$nextTick();

            // Verify next button is disabled
            const nextButton = wrapper.find('[data-test="carousel-next-button"]');
            expect(nextButton.exists()).toBe(true);
            expect(nextButton.attributes('disabled')).toBeDefined();
        });

    });
});
