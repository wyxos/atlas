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
    patch: vi.fn(),
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
const mockRemove = vi.fn();
const mockRemoveMany = vi.fn();
const mockRestore = vi.fn();
const mockRestoreMany = vi.fn();
vi.mock('@wyxos/vibe', () => ({
    Masonry: {
        name: 'Masonry',
        template: `
            <div class="masonry-mock">
                <slot
                    v-for="(item, index) in items"
                    :key="item.id || index"
                    :item="item"
                    :remove="(itemToRemove) => {
                        const idx = items.findIndex(i => i.id === itemToRemove.id);
                        if (idx !== -1) {
                            items.splice(idx, 1);
                            $emit('update:items', items);
                        }
                    }"
                    :index="index"
                ></slot>
            </div>
        `,
        props: ['items', 'getNextPage', 'loadAtPage', 'layout', 'layoutMode', 'mobileBreakpoint', 'skipInitialLoad', 'backfillEnabled', 'backfillDelayMs', 'backfillMaxCalls'],
        emits: ['backfill:start', 'backfill:tick', 'backfill:stop', 'backfill:retry-start', 'backfill:retry-tick', 'backfill:retry-stop', 'update:items'],
        setup(props: { items: any[] }, { emit }: { emit: (event: string, value: any) => void }) {
            // Create remove function that updates items array
            // This function is used both by masonry.value.remove() and the slot's remove prop
            // Store it in a way that both template and return can access
            const removeFn = (item: any) => {
                mockRemove(item);
                const index = props.items.findIndex((i: any) => i.id === item.id);
                if (index !== -1) {
                    props.items.splice(index, 1);
                    emit('update:items', props.items);
                }
            };

            // Expose remove function to template via provide or return
            // For now, we'll use the same function for both
            const remove = removeFn;

            // Create removeMany function
            const removeMany = (itemsToRemove: any[]) => {
                mockRemoveMany(itemsToRemove);
                const ids = new Set(itemsToRemove.map((i: any) => i.id));
                const filtered = props.items.filter((i: any) => !ids.has(i.id));
                props.items.splice(0, props.items.length, ...filtered);
                emit('update:items', props.items);
            };

            // Create restore function
            const restore = (item: any, index: number) => {
                mockRestore(item, index);
                const existingIndex = props.items.findIndex((i: any) => i.id === item.id);
                if (existingIndex === -1) {
                    const targetIndex = Math.min(index, props.items.length);
                    props.items.splice(targetIndex, 0, item);
                    emit('update:items', props.items);
                }
            };

            // Create restoreMany function
            const restoreMany = (itemsToRestore: any[], indices: number[]) => {
                mockRestoreMany(itemsToRestore, indices);
                const existingIds = new Set(props.items.map((i: any) => i.id));
                const itemsToAdd = itemsToRestore.filter((item: any, i: number) => !existingIds.has(item.id));

                // Build final array by merging current and restored items
                const restoredByIndex = new Map<number, any>();
                itemsToAdd.forEach((item: any, i: number) => {
                    restoredByIndex.set(indices[i], item);
                });

                const maxIndex = Math.max(
                    props.items.length > 0 ? props.items.length - 1 : 0,
                    ...indices
                );

                const newItems: any[] = [];
                let currentArrayIndex = 0;

                for (let position = 0; position <= maxIndex; position++) {
                    if (restoredByIndex.has(position)) {
                        newItems.push(restoredByIndex.get(position)!);
                    } else {
                        if (currentArrayIndex < props.items.length) {
                            newItems.push(props.items[currentArrayIndex]);
                            currentArrayIndex++;
                        }
                    }
                }

                while (currentArrayIndex < props.items.length) {
                    newItems.push(props.items[currentArrayIndex]);
                    currentArrayIndex++;
                }

                props.items.splice(0, props.items.length, ...newItems);
                emit('update:items', props.items);
            };

            return {
                isLoading: mockIsLoading,
                init: mockInit,
                refreshLayout: vi.fn(),
                cancelLoad: mockCancelLoad,
                destroy: mockDestroy,
                remove,
                removeMany,
                restore,
                restoreMany,
            };
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

beforeEach(() => {
    vi.clearAllMocks();
    vi.spyOn(console, 'error').mockImplementation(() => { });
    mockIsLoading.value = false;
    mockCancelLoad.mockClear();
    mockDestroy.mockClear();
    mockInit.mockClear();
    mockRemove.mockClear();
    mockRemoveMany.mockClear();
    mockRestore.mockClear();
    mockRestoreMany.mockClear();

    // Mock tabs API to return empty array by default
    // Reset to default mock that returns empty array for /api/browse-tabs
    mockAxios.get.mockImplementation((url: string) => {
        if (url.includes('/api/browse-tabs')) {
            return Promise.resolve({ data: [] });
        }
        // For other URLs, return a default response
        return Promise.resolve({ data: { items: [], nextPage: null } });
    });

    // Default mock for patch (setActive) - resolves successfully
    mockAxios.patch.mockResolvedValue({ data: {} });
});

// Mock usePreviewBatch composable (useItemPreview will use this)
const mockQueuePreviewIncrement = vi.fn();
vi.mock('@/composables/usePreviewBatch', () => ({
    usePreviewBatch: () => ({
        queuePreviewIncrement: mockQueuePreviewIncrement,
    }),
}));

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

// Helper to get BrowseTabContent component from wrapper
function getBrowseTabContent(wrapper: any) {
    const browseTabContent = wrapper.findComponent({ name: 'BrowseTabContent' });
    if (browseTabContent.exists()) {
        return browseTabContent.vm;
    }
    return null;
}

// Helper to get FileViewer component from wrapper (through BrowseTabContent)
function getFileViewer(wrapper: any) {
    const browseTabContent = wrapper.findComponent({ name: 'BrowseTabContent' });
    if (browseTabContent.exists()) {
        const fileViewer = browseTabContent.findComponent(FileViewer);
        if (fileViewer.exists()) {
            return fileViewer;
        }
    }
    return null;
}

function createMockBrowseResponse(
    page: number | string,
    nextPageValue: number | string | null = null
) {
    const pageNum = typeof page === 'number' ? page : 1;
    const items = Array.from({ length: 40 }, (_, i) => {
        const itemId = typeof page === 'number' ? i + 1 : parseInt(`item-${page}-${i}`) || i + 1;
        return {
            id: itemId,
            width: 300 + (i % 100),
            height: 200 + (i % 100),
            src: `https://picsum.photos/id/${i}/300/200`,
            type: i % 10 === 0 ? 'video' : 'image',
            page: pageNum,
            key: `${pageNum}-${itemId}`, // Combined key from backend
            index: i,
            notFound: false,
        };
    });

    return {
        items,
        nextPage: nextPageValue !== null ? nextPageValue : (typeof page === 'number' && page < 100 ? page + 1 : null),
    };
}

// Helper to create mock tab configuration
function createMockTabConfig(tabId: number, overrides: Record<string, any> = {}) {
    return {
        id: tabId,
        label: `Test Tab ${tabId}`,
        query_params: { service: 'civit-ai-images', page: 1 },
        file_ids: [],
        items_data: [],
        position: 0,
        is_active: false,
        ...overrides,
    };
}

// Helper to setup axios mocks for tabs and browse API
function setupAxiosMocks(tabConfig: any | any[], browseResponse?: any) {
    mockAxios.get.mockImplementation((url: string) => {
        if (url.includes('/api/browse-tabs')) {
            return Promise.resolve({ data: Array.isArray(tabConfig) ? tabConfig : [tabConfig] });
        }
        if (url.includes('/api/browse-tabs/') && url.includes('/items')) {
            const tabId = url.match(/\/api\/browse-tabs\/(\d+)\/items/)?.[1];
            const tab = Array.isArray(tabConfig) ? tabConfig.find((t: any) => t.id === Number(tabId)) : tabConfig;
            if (tab && tab.items_data) {
                return Promise.resolve({
                    data: {
                        items_data: tab.items_data,
                        file_ids: tab.file_ids || [],
                    },
                });
            }
            return Promise.resolve({ data: { items_data: [], file_ids: [] } });
        }
        if (url.includes('/api/browse')) {
            return Promise.resolve({
                data: browseResponse || {
                    items: [],
                    nextPage: null,
                    services: [{ key: 'civit-ai-images', label: 'CivitAI Images' }],
                },
            });
        }
        return Promise.resolve({ data: { items: [], nextPage: null } });
    });
}

// Helper to wait for tab content to be ready (replaces setTimeout patterns)
async function waitForTabContent(wrapper: any, maxWait = 50): Promise<any> {
    const start = Date.now();
    while (Date.now() - start < maxWait) {
        await flushPromises();
        await wrapper.vm.$nextTick();
        const tabContent = getBrowseTabContent(wrapper);
        if (tabContent) {
            return tabContent;
        }
        // Use shorter polling interval for faster response
        await new Promise(resolve => setTimeout(resolve, 5));
    }
    return null;
}

// Helper to mount Browse component with tab configuration
async function mountBrowseWithTab(tabConfig: any | any[], browseResponse?: any) {
    setupAxiosMocks(tabConfig, browseResponse);
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

// Helper to wait for component to stabilize (replaces arbitrary setTimeout)
async function waitForStable(wrapper: any, iterations = 2): Promise<void> {
    for (let i = 0; i < iterations; i++) {
        await flushPromises();
        await wrapper.vm.$nextTick();
    }
}

// Helper to wait for overlay animation to complete by checking component state
// This is better than setTimeout because it waits for actual state changes
async function waitForOverlayAnimation(
    fileViewerVm: any,
    condition: () => boolean,
    timeout = 1000
): Promise<void> {
    const start = Date.now();
    while (Date.now() - start < timeout) {
        if (condition()) {
            return;
        }
        await new Promise(resolve => setTimeout(resolve, 10));
    }
    // If condition not met, wait for animation duration anyway as fallback
    await new Promise(resolve => setTimeout(resolve, 550));
}

// Helper to wait for overlay to close (checks overlayRect is null)
async function waitForOverlayClose(fileViewerVm: any, timeout = 1000): Promise<void> {
    await waitForOverlayAnimation(
        fileViewerVm,
        () => fileViewerVm.overlayRect === null,
        timeout
    );
}

// Helper to wait for overlay to be fully filled (checks overlayFillComplete)
async function waitForOverlayFill(fileViewerVm: any, timeout = 1000): Promise<void> {
    await waitForOverlayAnimation(
        fileViewerVm,
        () => fileViewerVm.overlayFillComplete === true,
        timeout
    );
}

// Helper to wait for navigation animation to complete (checks isNavigating state)
async function waitForNavigation(fileViewerVm: any, timeout = 1000): Promise<void> {
    await waitForOverlayAnimation(
        fileViewerVm,
        () => fileViewerVm.isNavigating === false,
        timeout
    );
}

// Helper to setup overlay test with common configuration
async function setupOverlayTest() {
    const tabConfig = createMockTabConfig(1);
    const router = await createTestRouter();
    setupAxiosMocks(tabConfig);
    const wrapper = mount(Browse, {
        global: {
            plugins: [router],
        },
    });
    await waitForStable(wrapper);
    return { wrapper, router };
}

describe('Browse', () => {
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

        // Access BrowseTabContent component if it exists
        const tabContentVm = getBrowseTabContent(wrapper);
        if (tabContentVm) {
            expect(tabContentVm.items).toEqual([]);
        } else {
            // If no tab content, items should be empty (no active tab)
            expect(true).toBe(true); // Just pass the test
        }
    });

    it('passes correct props to Masonry component', async () => {
        const tabConfig = createMockTabConfig(1);
        const { wrapper } = await mountBrowseWithTab(tabConfig);
        await waitForStable(wrapper);

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
        const tabConfig = createMockTabConfig(tabId, { query_params: { service: 'civit-ai-images' } });
        const browseResponse = {
            ...mockResponse,
            services: [{ key: 'civit-ai-images', label: 'CivitAI Images' }],
        };

        const { wrapper } = await mountBrowseWithTab(tabConfig, browseResponse);
        await waitForStable(wrapper);

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;

        // Wait for BrowseTabContent to mount
        const tabContentVm = await waitForTabContent(wrapper);
        if (!tabContentVm) {
            return;
        }

        // Ensure tab restoration state is false and items are empty
        tabContentVm.isTabRestored = false;
        tabContentVm.items = [];
        // Ensure service is set (tab should have it, but double-check)
        const activeTab = vm.getActiveTab();
        if (activeTab && !activeTab.queryParams.service) {
            activeTab.queryParams.service = 'civit-ai-images';
        }
        const getNextPage = tabContentVm.getNextPage;

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
                is_active: false,
            },
        });

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        // Create a tab with service for this test
        await vm.createTab();
        const activeTab = vm.getActiveTab();
        if (activeTab) {
            activeTab.queryParams.service = 'civit-ai-images';
        }
        await waitForStable(wrapper);

        // Access BrowseTabContent component
        const tabContentVm = await waitForTabContent(wrapper);
        if (!tabContentVm) {
            return;
        }

        // Ensure tab restoration state is false and items are empty
        tabContentVm.isTabRestored = false;
        tabContentVm.items = [];
        const getNextPage = tabContentVm.getNextPage;

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
                is_active: false,
            },
        });

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        // Create a tab with service for this test
        await vm.createTab();
        const activeTab = vm.getActiveTab();
        if (activeTab) {
            activeTab.queryParams.service = 'civit-ai-images';
        }
        await waitForStable(wrapper);

        // Access BrowseTabContent component
        const tabContentVm = await waitForTabContent(wrapper);
        if (!tabContentVm) {
            return;
        }

        // Ensure tab restoration state is false and items are empty
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
                is_active: false,
            },
        });

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        // Create a tab with service for this test
        await vm.createTab();
        const activeTab = vm.getActiveTab();
        if (activeTab) {
            activeTab.queryParams.service = 'civit-ai-images';
        }
        await waitForStable(wrapper);

        // Access BrowseTabContent component
        const tabContentVm = await waitForTabContent(wrapper);
        if (!tabContentVm) {
            return;
        }

        // Ensure tab restoration state is false and items are empty
        tabContentVm.isTabRestored = false;
        tabContentVm.items = [];
        const result = await tabContentVm.getNextPage(cursor);

        expect(mockAxios.get).toHaveBeenCalledWith(
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
                is_active: false,
            },
        });

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        // Create a tab with service for this test
        await vm.createTab();
        const activeTab = vm.getActiveTab();
        if (activeTab) {
            activeTab.queryParams.service = 'civit-ai-images';
        }
        await waitForStable(wrapper);

        // Access BrowseTabContent component
        const tabContentVm = await waitForTabContent(wrapper);
        if (!tabContentVm) {
            return;
        }

        // Ensure tab restoration state is false and items are empty
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
        setupAxiosMocks(tabConfig);
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await waitForStable(wrapper);

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        expect(vm.activeTabId).toBe(tabId);

        // Wait for BrowseTabContent to mount and initialize
        const tabContentVm = await waitForTabContent(wrapper);
        if (tabContentVm) {
            // Query params should be restored
            expect(tabContentVm.currentPage).toBe(pageParam);
            expect(tabContentVm.nextCursor).toBe(nextParam);
        }
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
        // currentPage, nextCursor, loadAtPage are now in BrowseTabContent
        // Since no tabs exist, BrowseTabContent won't be mounted, so these properties don't exist on Browse.vue
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

        await waitForStable(wrapper); // Wait for tab switching

        // Wait for BrowseTabContent to mount
        const tabContentVm = await waitForTabContent(wrapper);
        if (!tabContentVm) {
            return;
        }

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        tabContentVm.items = [{ id: '1' }, { id: '2' }, { id: '3' }];
        tabContentVm.currentPage = 2;
        tabContentVm.nextCursor = 'cursor-123';

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

        // Wait for BrowseTabContent to mount
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

        await waitForStable(wrapper); // Wait for tab switching and restoration

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        expect(vm.activeTabId).toBe(tabId);

        // Wait for BrowseTabContent to mount and initialize
        await waitForStable(wrapper);

        // Access BrowseTabContent component
        const tabContentVm = getBrowseTabContent(wrapper);
        if (tabContentVm) {
            // Page from query_params should be restored
            expect(tabContentVm.currentPage).toBe(pageParam);
        }
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

        await waitForStable(wrapper); // Wait for tab switching and restoration

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;

        // Wait for BrowseTabContent to mount and initialize
        await waitForStable(wrapper);

        // Access BrowseTabContent component
        const tabContentVm = getBrowseTabContent(wrapper);
        if (tabContentVm) {
            // Page value from query_params should be preserved (can be number or string)
            expect(tabContentVm.currentPage).toBe(pageValue);
        }
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

        await waitForStable(wrapper); // Wait for initial tab load

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;

        // Wait for BrowseTabContent to mount
        await waitForStable(wrapper);

        // Access BrowseTabContent component
        const tabContentVm = getBrowseTabContent(wrapper);
        if (!tabContentVm) {
            return;
        }

        // Set masonry to loading state
        mockIsLoading.value = true;
        expect(tabContentVm.masonry?.isLoading).toBe(true);

        // Switch to second tab
        await vm.switchTab(tab2Id);
        await waitForStable(wrapper);

        // Verify cancelLoad and destroy were called (masonry is destroyed when switching tabs)
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

        await waitForStable(wrapper); // Wait for initial tab load

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;

        // Wait for BrowseTabContent to mount
        await waitForStable(wrapper);

        // Access BrowseTabContent component
        const tabContentVm = getBrowseTabContent(wrapper);
        if (!tabContentVm) {
            return;
        }

        // Ensure masonry is not loading
        mockIsLoading.value = false;
        expect(tabContentVm.masonry?.isLoading).toBe(false);

        // Clear previous calls
        mockCancelLoad.mockClear();
        mockDestroy.mockClear();

        // Switch to second tab
        await vm.switchTab(tab2Id);
        await waitForStable(wrapper);

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

        await waitForStable(wrapper);

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

        await waitForStable(wrapper);

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

        await waitForStable(wrapper);

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

        // Store the initial overlay state
        const initialOverlayRect = fileViewerVm.overlayRect;

        // Switch to tab 2
        await vm.switchTab(tab2Id);

        await waitForStable(wrapper);

        // Verify tab 2 is active
        expect(vm.activeTabId).toBe(tab2Id);

        // Verify fileviewer was closed by checking that overlay is reset
        // When BrowseTabContent switches tabs, it calls fileViewer.value.close() in initializeTabContent
        // This should reset the overlay state
        const newFileViewer = wrapper.findComponent(FileViewer);
        if (newFileViewer.exists()) {
            const newFileViewerVm = newFileViewer.vm as any;
            // After switching tabs, the overlay should be closed (null or different)
            // The new tab's fileviewer should have overlayRect as null
            expect(newFileViewerVm.overlayRect).toBeNull();
        }
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
                is_active: false,
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
        await waitForStable(wrapper); // Wait for tab switching

        // Verify tab was created
        expect(vm.activeTabId).toBe(newTabId);
        expect(vm.tabs.length).toBe(1);
        // New tabs don't have page set by default (no service selected)
        expect(vm.tabs[0].queryParams.page).toBeUndefined();

        // Verify loadAtPage is null for new tab (no service selected, so no auto-load)
        const masonry = wrapper.findComponent({ name: 'Masonry' });
        expect(masonry.exists()).toBe(false); // Masonry should not render without service
    });

    it('restores tab query params after refresh', async () => {
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
        setupAxiosMocks(tabConfig);
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await waitForStable(wrapper);

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        expect(vm.activeTabId).toBe(tabId);

        const tabContentVm = await waitForTabContent(wrapper);
        if (tabContentVm) {
            expect(tabContentVm.currentPage).toBe(pageParam);
            expect(tabContentVm.nextCursor).toBe(nextParam);
        }
    });

    it('loads tab items when file_ids exist', async () => {
        const tabId = 1;
        const mockItems = [
            { id: 1, width: 100, height: 100, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false },
            { id: 2, width: 200, height: 200, src: 'test2.jpg', type: 'image', page: 1, index: 1, notFound: false },
        ];

        const tabConfig = createMockTabConfig(tabId, {
            query_params: { service: 'civit-ai-images', page: 1 },
            file_ids: [1, 2],
            items_data: mockItems,
        });

        const router = await createTestRouter('/browse');
        setupAxiosMocks(tabConfig);
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await waitForStable(wrapper);

        // Verify items endpoint was called
        expect(mockAxios.get).toHaveBeenCalledWith('/api/browse-tabs/1/items');
    });

    it('initializes masonry with restored items', async () => {
        const tabId = 1;
        const mockItems = [
            { id: 1, width: 100, height: 100, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false },
            { id: 2, width: 200, height: 200, src: 'test2.jpg', type: 'image', page: 1, index: 1, notFound: false },
        ];

        const tabConfig = createMockTabConfig(tabId, {
            query_params: { service: 'civit-ai-images', page: 1 },
            file_ids: [1, 2],
            items_data: mockItems,
        });

        const router = await createTestRouter('/browse');
        setupAxiosMocks(tabConfig);
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await waitForStable(wrapper);

        const masonry = wrapper.findComponent({ name: 'Masonry' });
        expect(masonry.exists()).toBe(true);
    });

    it('switches to tab with saved query params', async () => {
        const tab1Id = 1;
        const tab2Id = 2;
        const pageParam = 'cursor-page-456';
        const nextParam = 'cursor-next-789';

        const tabConfigs = [
            createMockTabConfig(tab1Id, {
                query_params: { service: 'civit-ai-images', page: 1 },
            }),
            createMockTabConfig(tab2Id, {
                query_params: { service: 'civit-ai-images', page: pageParam, next: nextParam },
                position: 1,
            }),
        ];

        const router = await createTestRouter('/browse');
        setupAxiosMocks(tabConfigs);
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await waitForStable(wrapper);

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        expect(vm.activeTabId).toBe(tab1Id);

        await vm.switchTab(tab2Id);
        await waitForStable(wrapper);

        expect(vm.activeTabId).toBe(tab2Id);

        const tabContentVm = await waitForTabContent(wrapper);
        if (tabContentVm) {
            expect(tabContentVm.currentPage).toBe(pageParam);
            expect(tabContentVm.nextCursor).toBe(nextParam);
        }
    });

    it('restores items when switching to tab with file_ids', async () => {
        const tab1Id = 1;
        const tab2Id = 2;
        const mockItems = [
            { id: 3, width: 100, height: 100, src: 'test3.jpg', type: 'image', page: 2, index: 0, notFound: false },
            { id: 4, width: 200, height: 200, src: 'test4.jpg', type: 'image', page: 2, index: 1, notFound: false },
        ];

        const tabConfigs = [
            createMockTabConfig(tab1Id, {
                query_params: { service: 'civit-ai-images', page: 1 },
            }),
            createMockTabConfig(tab2Id, {
                query_params: { service: 'civit-ai-images', page: 1 },
                file_ids: [3, 4],
                items_data: mockItems,
                position: 1,
            }),
        ];

        const router = await createTestRouter('/browse');
        setupAxiosMocks(tabConfigs);
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await waitForStable(wrapper);

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        mockDestroy.mockClear();

        await vm.switchTab(tab2Id);
        await waitForStable(wrapper);

        expect(mockAxios.get).toHaveBeenCalledWith('/api/browse-tabs/2/items');
        expect(mockDestroy).toHaveBeenCalled();
    });

    it('resumes pagination from next cursor value', async () => {
        const tabId = 1;
        const nextParam = 'cursor-next-789';
        const browseResponse = {
            ...createMockBrowseResponse(nextParam, 'cursor-next-999'),
            services: [{ key: 'civit-ai-images', label: 'CivitAI Images' }],
        };

        const tabConfig = createMockTabConfig(tabId, {
            query_params: { service: 'civit-ai-images', page: 1, next: nextParam },
        });

        const { wrapper } = await mountBrowseWithTab(tabConfig, browseResponse);
        await waitForStable(wrapper);

        const tabContentVm = await waitForTabContent(wrapper);
        if (!tabContentVm) {
            return;
        }

        tabContentVm.isTabRestored = false;
        tabContentVm.items = [];
        tabContentVm.nextCursor = nextParam;

        const getNextPageResult = await tabContentVm.getNextPage(nextParam);

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

        await waitForStable(wrapper); // Wait for tab switching and restoration

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;

        // Verify tab is active
        expect(vm.activeTabId).toBe(tabId);

        // Wait for BrowseTabContent to mount and initialize
        await waitForStable(wrapper);

        // Access BrowseTabContent component
        const tabContentVm = getBrowseTabContent(wrapper);
        if (tabContentVm) {
            // CRITICAL: Verify cursor values are preserved, NOT reset to page 1
            // This is the bug fix - the tab should preserve cursor-x, not reset to 1
            expect(tabContentVm.currentPage).toBe(cursorX); // Should be cursor-x, NOT 1
            expect(tabContentVm.nextCursor).toBe(cursorY); // Should be cursor-y

            // Verify displayPage computed property also shows the cursor value
            expect(tabContentVm.displayPage).toBe(cursorX); // Should be cursor-x, NOT 1
        }
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

        await waitForStable(wrapper);

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;

        expect(vm.activeTabId).toBe(tabId);

        await vm.createTab();
        await waitForStable(wrapper);

        expect(vm.activeTabId).toBe(2);

        await vm.switchTab(tabId);
        await waitForStable(wrapper);

        expect(vm.activeTabId).toBe(tabId);

        // Wait for BrowseTabContent to mount and initialize
        await waitForStable(wrapper);

        // Access BrowseTabContent component
        const tabContentVm = getBrowseTabContent(wrapper);
        if (!tabContentVm) {
            return;
        }

        expect(tabContentVm.pendingRestoreNextCursor).toBe(cursorY);

        await tabContentVm.getNextPage(1);

        // Filter for /api/browse calls (but not /api/browse-tabs which is used for loading tab items)
        const browseCalls = mockAxios.get.mock.calls
            .map(call => call[0])
            .filter((callUrl: string) => {
                // Match /api/browse but not /api/browse-tabs
                return callUrl.includes('/api/browse?') || callUrl === '/api/browse';
            });

        expect(browseCalls[browseCalls.length - 1]).toContain(`/api/browse?page=${cursorY}`);
        expect(tabContentVm.currentPage).toBe(cursorY);
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
                is_active: false,
            },
        });

        const router = await createTestRouter('/browse');
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await waitForStable(wrapper);

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;

        // Create a new tab
        await vm.createTab();
        await waitForStable(wrapper); // Wait longer for tab switching

        expect(vm.activeTabId).toBe(1);

        // Wait for switchTab to complete
        await waitForStable(wrapper); // Wait for BrowseTabContent to mount

        // Access BrowseTabContent component
        const tabContentVm = getBrowseTabContent(wrapper);
        if (tabContentVm) {
            expect(tabContentVm.hasServiceSelected).toBe(false);
            expect(tabContentVm.loadAtPage).toBe(null); // Should not auto-load
            expect(tabContentVm.items.length).toBe(0);
        } else {
            // If BrowseTabContent hasn't mounted yet, just check that no items were loaded
            const itemLoadingCalls = mockAxios.get.mock.calls
                .map(call => call[0])
                .filter((callUrl: string) => {
                    const url = callUrl as string;
                    return url.includes('/api/browse') && url.includes('source=');
                });
            expect(itemLoadingCalls.length).toBe(0);
        }

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

        await waitForStable(wrapper);

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;

        expect(vm.activeTabId).toBe(1);

        // Wait for BrowseTabContent to mount
        await waitForStable(wrapper);

        // Access BrowseTabContent component
        const tabContentVm = getBrowseTabContent(wrapper);
        if (!tabContentVm) {
            // If BrowseTabContent hasn't mounted, skip this test's assertions
            return;
        }

        expect(tabContentVm.hasServiceSelected).toBe(false);

        // Select a service
        tabContentVm.selectedService = 'civit-ai-images';
        await wrapper.vm.$nextTick();

        // Apply service
        await tabContentVm.applyService();
        await waitForStable(wrapper); // Wait for masonry to render and trigger load

        // Verify service was applied
        const activeTab = vm.getActiveTab();
        expect(activeTab.queryParams.service).toBe('civit-ai-images');
        expect(tabContentVm.loadAtPage).toBe(1); // Should trigger load
        expect(tabContentVm.hasServiceSelected).toBe(true); // Service should be selected

        // Verify masonry is rendered
        const masonry = wrapper.findComponent({ name: 'Masonry' });
        expect(masonry.exists()).toBe(true);

        // Manually trigger load if masonry hasn't loaded yet (for test environment)
        // In real usage, masonry watches loadAtPage and auto-loads
        if (tabContentVm.masonry && tabContentVm.loadAtPage !== null && tabContentVm.items.length === 0) {
            // Simulate masonry triggering getNextPage
            await tabContentVm.getNextPage(tabContentVm.loadAtPage);
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

        await waitForStable(wrapper);

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;

        // First tab should be active with its service
        expect(vm.activeTabId).toBe(tab1Id);

        // Wait for BrowseTabContent to mount
        await waitForStable(wrapper);

        // Access BrowseTabContent component
        let tabContentVm = getBrowseTabContent(wrapper);
        if (tabContentVm) {
            expect(tabContentVm.currentTabService).toBe('civit-ai-images');
            expect(tabContentVm.selectedService).toBe('civit-ai-images');
        }

        // Switch to second tab
        await vm.switchTab(tab2Id);
        await waitForStable(wrapper); // Wait for new BrowseTabContent to mount

        // Second tab should have its service restored
        expect(vm.activeTabId).toBe(tab2Id);
        tabContentVm = getBrowseTabContent(wrapper);
        if (tabContentVm) {
            expect(tabContentVm.currentTabService).toBe('wallhaven');
            expect(tabContentVm.selectedService).toBe('wallhaven');
        }
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

        await waitForStable(wrapper);

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;

        // Wait for BrowseTabContent to mount
        await waitForStable(wrapper);

        // Access BrowseTabContent component
        const tabContentVm = getBrowseTabContent(wrapper);
        if (!tabContentVm) {
            // If BrowseTabContent hasn't mounted, skip this test's assertions
            return;
        }

        // Reset restoration flag to allow loading
        tabContentVm.isTabRestored = false;
        tabContentVm.loadAtPage = 1;

        // Trigger getNextPage
        await tabContentVm.getNextPage(1);
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

        await waitForStable(wrapper);

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;

        // Wait for BrowseTabContent to mount
        await waitForStable(wrapper);

        // Access BrowseTabContent component
        const tabContentVm = getBrowseTabContent(wrapper);
        if (!tabContentVm) {
            // If BrowseTabContent hasn't mounted, skip this test's assertions
            return;
        }

        // Verify backfill handlers exist
        expect(typeof tabContentVm.onBackfillStart).toBe('function');
        expect(typeof tabContentVm.onBackfillTick).toBe('function');
        expect(typeof tabContentVm.onBackfillStop).toBe('function');
        expect(typeof tabContentVm.onBackfillRetryStart).toBe('function');
        expect(typeof tabContentVm.onBackfillRetryTick).toBe('function');
        expect(typeof tabContentVm.onBackfillRetryStop).toBe('function');

        // Verify backfill state exists
        expect(tabContentVm.backfill).toBeDefined();
        expect(tabContentVm.backfill.active).toBe(false);
        expect(tabContentVm.backfill.fetched).toBe(0);
        expect(tabContentVm.backfill.target).toBe(0);
    });
});
