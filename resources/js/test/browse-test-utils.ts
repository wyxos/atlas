import { vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { createRouter, createMemoryHistory } from 'vue-router';
import { type Ref } from 'vue';
import Browse from '../pages/Browse.vue';
import { index as tabIndex, items as tabItems } from '@/actions/App/Http/Controllers/TabController';
import { index as browseIndex } from '@/actions/App/Http/Controllers/BrowseController';

/**
 * Type for the Browse mocks object
 */
export interface BrowseMocks {
    mockAxios: {
        get: ReturnType<typeof vi.fn>;
        post: ReturnType<typeof vi.fn>;
        put: ReturnType<typeof vi.fn>;
        delete: ReturnType<typeof vi.fn>;
        patch: ReturnType<typeof vi.fn>;
    };
    mockIsLoading: Ref<boolean>;
    mockCancelLoad: ReturnType<typeof vi.fn>;
    mockDestroy: ReturnType<typeof vi.fn>;
    mockInit: ReturnType<typeof vi.fn>;
    mockRemove: ReturnType<typeof vi.fn>;
    mockRemoveMany: ReturnType<typeof vi.fn>;
    mockRestore: ReturnType<typeof vi.fn>;
    mockRestoreMany: ReturnType<typeof vi.fn>;
    mockQueuePreviewIncrement: ReturnType<typeof vi.fn>;
}

/**
 * Creates the Masonry mock component factory for vi.mock('@wyxos/vibe')
 */
export function createVibeMockFactory(mocks: BrowseMocks) {
    return {
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
            props: ['items', 'getPage', 'layout', 'layoutMode', 'mobileBreakpoint', 'init', 'mode', 'backfillDelayMs', 'backfillMaxCalls'],
            emits: ['backfill:start', 'backfill:tick', 'backfill:stop', 'backfill:retry-start', 'backfill:retry-tick', 'backfill:retry-stop', 'update:items'],
            setup(props: { items: any[] }, { emit }: { emit: (event: string, value: any) => void }) {
                const removeFn = (item: any) => {
                    mocks.mockRemove(item);
                    const index = props.items.findIndex((i: any) => i.id === item.id);
                    if (index !== -1) {
                        props.items.splice(index, 1);
                        emit('update:items', props.items);
                    }
                };

                const remove = removeFn;

                const removeMany = (itemsToRemove: any[]) => {
                    mocks.mockRemoveMany(itemsToRemove);
                    const ids = new Set(itemsToRemove.map((i: any) => i.id));
                    const filtered = props.items.filter((i: any) => !ids.has(i.id));
                    props.items.splice(0, props.items.length, ...filtered);
                    emit('update:items', props.items);
                };

                const restore = (item: any, index: number) => {
                    mocks.mockRestore(item, index);
                    const existingIndex = props.items.findIndex((i: any) => i.id === item.id);
                    if (existingIndex === -1) {
                        const targetIndex = Math.min(index, props.items.length);
                        props.items.splice(targetIndex, 0, item);
                        emit('update:items', props.items);
                    }
                };

                const restoreMany = (itemsToRestore: any[], indices: number[]) => {
                    mocks.mockRestoreMany(itemsToRestore, indices);
                    const existingIds = new Set(props.items.map((i: any) => i.id));
                    const itemsToAdd = itemsToRestore.filter((item: any) => !existingIds.has(item.id));

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

                // Use getter to mimic Vue's ref auto-unwrapping behavior
                // When accessing masonry.value.isLoading, it returns boolean, not ref
                const exposed = {
                    init: mocks.mockInit,
                    refreshLayout: vi.fn(),
                    cancelLoad: mocks.mockCancelLoad,
                    destroy: mocks.mockDestroy,
                    remove,
                    removeMany,
                    restore,
                    restoreMany,
                };
                Object.defineProperty(exposed, 'isLoading', {
                    get() { return mocks.mockIsLoading.value; },
                    set(val: boolean) { mocks.mockIsLoading.value = val; },
                    enumerable: true,
                });
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
            emits: ['mouseenter', 'mouseleave', 'preload:success', 'in-view'],
        },
    };
}

/**
 * Setup function to be called in beforeEach
 */
export function setupBrowseTestMocks(mocks: BrowseMocks): void {
    vi.clearAllMocks();
    vi.spyOn(console, 'error').mockImplementation(() => { });
    mocks.mockIsLoading.value = false;
    mocks.mockCancelLoad.mockClear();
    mocks.mockDestroy.mockClear();
    mocks.mockInit.mockClear();
    mocks.mockRemove.mockClear();
    mocks.mockRemoveMany.mockClear();
    mocks.mockRestore.mockClear();
    mocks.mockRestoreMany.mockClear();

    mocks.mockAxios.get.mockImplementation((url: string) => {
        if (url.includes(tabIndex.definition.url) && !url.includes('/items')) {
            return Promise.resolve({ data: [] });
        }
        return Promise.resolve({ data: { items: [], nextPage: null } });
    });

    mocks.mockAxios.patch.mockResolvedValue({ data: {} });
}

/**
 * Creates a test router for Browse page tests
 */
export async function createTestRouter(initialPath = '/browse') {
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

/**
 * Get TabContent component from wrapper
 */
export function getTabContent(wrapper: any) {
    const tabContent = wrapper.findComponent({ name: 'TabContent' });
    if (tabContent.exists()) {
        return tabContent.vm;
    }
    return null;
}

/**
 * Wait for component to stabilize
 */
export async function waitForStable(wrapper: any, iterations = 2): Promise<void> {
    for (let i = 0; i < iterations; i++) {
        await flushPromises();
        await wrapper.vm.$nextTick();
    }
}

/**
 * Wait for tab content to be ready
 */
export async function waitForTabContent(wrapper: any, maxWait = 50): Promise<any> {
    const start = Date.now();
    while (Date.now() - start < maxWait) {
        await flushPromises();
        await wrapper.vm.$nextTick();
        const tabContent = getTabContent(wrapper);
        if (tabContent) {
            return tabContent;
        }
        await new Promise(resolve => setTimeout(resolve, 5));
    }
    return null;
}

/**
 * Create mock tab configuration
 */
export function createMockTabConfig(tabId: number, overrides: Record<string, any> = {}) {
    return {
        id: tabId,
        label: `Test Tab ${tabId}`,
        query_params: { service: 'civit-ai-images', page: 1 },
        items: [],
        position: 0,
        is_active: true, // Default to active so tests can access tab content
        ...overrides,
    };
}

/**
 * Setup axios mocks for tabs and browse API
 */
export function setupAxiosMocks(mocks: BrowseMocks, tabConfig: any | any[], browseResponse?: any) {
    mocks.mockAxios.get.mockImplementation((url: string) => {
        if (url.includes(tabIndex.definition.url) && !url.includes('/items')) {
            // Return tab configs for initial load without file-related data
            const configs = Array.isArray(tabConfig) ? tabConfig : [tabConfig];
            const tabsForIndex = configs.map((tab: any) => {
                const { file_ids, has_files, items, ...rest } = tab;
                return rest;
            });
            return Promise.resolve({ data: tabsForIndex });
        }
        if (url.includes(tabIndex.definition.url) && url.includes('/items')) {
            // Extract tab ID from URL (e.g., /api/tabs/1/items)
            const tabIdMatch = url.match(/\/api\/tabs\/(\d+)\/items/);
            const tabId = tabIdMatch ? tabIdMatch[1] : null;
            const tab = tabId ? (Array.isArray(tabConfig) ? tabConfig.find((t: any) => t.id === Number(tabId)) : tabConfig) : null;
            if (tab && tab.items) {
                return Promise.resolve({
                    data: {
                        items: tab.items,
                    },
                });
            }
            return Promise.resolve({ data: { items: [] } });
        }
        if (url.includes(browseIndex.definition.url)) {
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

/**
 * Create mock browse response with items
 */
export function createMockBrowseResponse(
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
            key: `${pageNum}-${itemId}`,
            index: i,
            notFound: false,
        };
    });

    return {
        items,
        nextPage: nextPageValue !== null ? nextPageValue : (typeof page === 'number' && page < 100 ? page + 1 : null),
    };
}

/**
 * Mount Browse component with tab configuration
 */
export async function mountBrowseWithTab(
    mocks: BrowseMocks,
    tabConfig: any | any[],
    browseResponse?: any,
    mountFn?: (Browse: any, options: any) => any
) {
    setupAxiosMocks(mocks, tabConfig, browseResponse);
    const router = await createTestRouter();

    // Import Browse dynamically to avoid circular dependency
    const Browse = (await import('../pages/Browse.vue')).default;

    const wrapper = mountFn
        ? mountFn(Browse, { global: { plugins: [router] } })
        : (await import('@vue/test-utils')).mount(Browse, { global: { plugins: [router] } });

    await flushPromises();
    await wrapper.vm.$nextTick();
    return { wrapper, router };
}

/**
 * Get FileViewer component from wrapper (through TabContent)
 */
export function getFileViewer(wrapper: any, FileViewerComponent: any) {
    const tabContent = wrapper.findComponent({ name: 'TabContent' });
    if (tabContent.exists()) {
        const fileViewer = tabContent.findComponent(FileViewerComponent);
        if (fileViewer.exists()) {
            return fileViewer;
        }
    }
    return null;
}

/**
 * Wait for overlay animation to complete by checking component state
 */
export async function waitForOverlayAnimation(
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

/**
 * Wait for overlay to close (checks overlayRect is null)
 */
export async function waitForOverlayClose(fileViewerVm: any, timeout = 1000): Promise<void> {
    await waitForOverlayAnimation(
        fileViewerVm,
        () => fileViewerVm.overlayRect === null,
        timeout
    );
}

/**
 * Wait for overlay to be fully filled (checks overlayFillComplete)
 */
export async function waitForOverlayFill(fileViewerVm: any, timeout = 1000): Promise<void> {
    await waitForOverlayAnimation(
        fileViewerVm,
        () => fileViewerVm.overlayFillComplete === true,
        timeout
    );
}

/**
 * Wait for navigation animation to complete (checks isNavigating state)
 */
export async function waitForNavigation(fileViewerVm: any, timeout = 1000): Promise<void> {
    await waitForOverlayAnimation(
        fileViewerVm,
        () => fileViewerVm.isNavigating === false,
        timeout
    );
}

/**
 * Setup getBoundingClientRect mock for overlay positioning tests
 */
export function setupBoundingClientRectMock(): void {
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
}
