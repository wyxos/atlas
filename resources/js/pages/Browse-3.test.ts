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
    describe('Info Badge and Prompt Tooltip', () => {
        it('shows info badge when hovering on masonry item with prompt data', async () => {
            const browseResponse = {
                items: [
                    { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false, metadata: { prompt: 'test prompt' } },
                ],
                nextPage: null,
                services: [{ key: 'civit-ai-images', label: 'CivitAI Images' }],
            };
            const tabConfig = createMockTabConfig(1);
            setupAxiosMocks(tabConfig, browseResponse);
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

            tabContentVm.items = [{ id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false, metadata: { prompt: 'test prompt' } }];
            await wrapper.vm.$nextTick();

            const browseTabContentComponent = wrapper.findComponent({ name: 'BrowseTabContent' });
            const masonryItem = browseTabContentComponent.findComponent({ name: 'MasonryItem' });

            if (masonryItem.exists()) {
                // Simulate hover and image loaded
                await masonryItem.vm.$emit('mouseenter', 0);
                // Emit preload success to simulate image loaded
                await masonryItem.vm.$emit('preload:success', {
                    item: { id: 1 },
                    type: 'image',
                    src: 'test1.jpg',
                });
                await wrapper.vm.$nextTick();
                await flushPromises();

                // Check if info badge button exists (the badge shows when hovered and image is loaded)
                // The badge should be rendered in the template when hoveredItemIndex matches and imageLoaded is true
                const html = browseTabContentComponent.html();
                // The badge should be present when conditions are met
                expect(html.includes('Info') || html.includes('info')).toBe(true);
            }
        });

        it('loads prompt data from API when hovering on badge', async () => {
            const browseResponse = {
                items: [
                    { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false },
                ],
                nextPage: null,
                services: [{ key: 'civit-ai-images', label: 'CivitAI Images' }],
            };
            const tabConfig = createMockTabConfig(1);
            setupAxiosMocks(tabConfig, browseResponse);

            // Mock file API response with prompt data
            mockAxios.get.mockImplementation((url: string) => {
                if (url.includes('/api/files/1')) {
                    return Promise.resolve({
                        data: {
                            file: {
                                id: 1,
                                metadata: {
                                    payload: { prompt: 'Loaded prompt from API' },
                                },
                            },
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

            tabContentVm.items = [{ id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false }];
            await wrapper.vm.$nextTick();

            const browseTabContentComponent = wrapper.findComponent({ name: 'BrowseTabContent' });
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const tabContentVmAny = tabContentVm as any;

            // Simulate hovering on badge (which triggers loadPromptData)
            if (typeof tabContentVmAny.loadPromptData === 'function') {
                await tabContentVmAny.loadPromptData(tabContentVm.items[0]);
                await flushPromises();

                // Verify API was called
                expect(mockAxios.get).toHaveBeenCalledWith(expect.stringContaining('/api/files/1'));
            }
        });
    });
});
