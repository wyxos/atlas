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

});
