import { vi, type Mock } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { createRouter, createMemoryHistory } from 'vue-router';
import { type Ref } from 'vue';
import Browse from '../pages/Browse.vue';
import { index as tabIndex } from '@/actions/App/Http/Controllers/TabController';
import { index as browseIndex } from '@/actions/App/Http/Controllers/BrowseController';

/**
 * Type for the Browse mocks object
 */
export interface BrowseMocks {
    mockAxios: {
        get: Mock;
        post: Mock;
        put: Mock;
        delete: Mock;
        patch: Mock;
    };
    mockIsLoading: Ref<boolean>;
    mockCancelLoad: Mock;
    mockRemove: Mock;
    mockRestore: Mock;
    mockQueuePreviewIncrement: Mock;
}

/**
 * Creates the page-test Vibe mock factory for vi.mock('@wyxos/vibe').
 * It mirrors the current public Masonry instance contract instead of older
 * app-local helper methods.
 */
export function createVibePageMock(
    mocks: {
        mockIsLoading: { value: boolean };
        mockCancelLoad: Mock;
        mockRemove: Mock;
        mockRestore: Mock;
    }
) {
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
            props: ['items', 'getContent', 'getPage', 'page', 'layout', 'layoutMode', 'init', 'mode', 'restoredPages', 'pageSize', 'gapX', 'gapY'],
            emits: ['update:items', 'preloaded', 'failures'],
            setup(props: { items: any[]; getPage?: (page: number | string) => Promise<{ items?: any[]; nextPage?: number | string | null }> }, { emit }: { emit: (event: string, value: any) => void }) {
                let nextPage: number | string | null = null;
                let hasReachedEnd = false;
                let pagesLoaded: Array<number | string> = Array.isArray(props.restoredPages)
                    ? [...props.restoredPages]
                    : (props.page !== undefined && props.page !== null ? [props.page] : []);

                const normalizeIds = (itemsOrIds: any) => {
                    const raw = Array.isArray(itemsOrIds) ? itemsOrIds : [itemsOrIds];
                    return new Set(
                        raw
                            .map((itemOrId: any) => typeof itemOrId === 'object' ? itemOrId?.id : itemOrId)
                            .filter((value: any) => value !== undefined && value !== null)
                    );
                };

                const remove = async (itemsOrIds: any) => {
                    mocks.mockRemove(itemsOrIds);
                    const ids = normalizeIds(itemsOrIds);
                    if (ids.size === 0) {
                        return;
                    }

                    const nextItems = props.items.filter((item: any) => !ids.has(item?.id));
                    if (nextItems.length === props.items.length) {
                        return;
                    }

                    props.items.splice(0, props.items.length, ...nextItems);
                    emit('update:items', props.items);
                };

                const restore = async (itemsOrIds: any) => {
                    mocks.mockRestore(itemsOrIds);
                    const raw = Array.isArray(itemsOrIds) ? itemsOrIds : [itemsOrIds];
                    const itemsToRestore = raw.filter((item: any) => item && typeof item === 'object' && item.id != null);
                    if (itemsToRestore.length === 0) {
                        return;
                    }

                    const existingIds = new Set(props.items.map((item: any) => item?.id));
                    const uniqueItems = itemsToRestore.filter((item: any) => !existingIds.has(item.id));
                    if (uniqueItems.length === 0) {
                        return;
                    }

                    props.items.splice(props.items.length, 0, ...uniqueItems);
                    emit('update:items', props.items);
                };

                const loadNextPage = async () => {
                    const getContent = props.getContent ?? props.getPage;
                    if (!getContent || nextPage === null || nextPage === undefined) {
                        return;
                    }
                    const pageToLoad = nextPage;
                    const result = await getContent(pageToLoad);
                    const newItems = result?.items ?? [];
                    props.items.push(...newItems);
                    emit('update:items', props.items);
                    nextPage = result?.nextPage ?? null;
                    hasReachedEnd = nextPage === null;
                    pagesLoaded = [...pagesLoaded, pageToLoad];
                    return result;
                };

                const cancel = () => {
                    mocks.mockCancelLoad();
                };

                const exposed = {
                    cancel,
                    remove,
                    restore,
                    loadNextPage,
                    undo: vi.fn(),
                    forget: vi.fn(),
                };
                Object.defineProperty(exposed, 'isLoading', {
                    get() { return mocks.mockIsLoading.value; },
                    set(val: boolean) { mocks.mockIsLoading.value = val; },
                    enumerable: true,
                });
                Object.defineProperty(exposed, 'hasReachedEnd', {
                    get() { return hasReachedEnd; },
                    set(val: boolean) { hasReachedEnd = val; },
                    enumerable: true,
                });
                Object.defineProperty(exposed, 'nextPage', {
                    get() { return nextPage; },
                    set(val: number | string | null) { nextPage = val; },
                    enumerable: true,
                });
                Object.defineProperty(exposed, 'pagesLoaded', {
                    get() { return pagesLoaded; },
                    set(val: Array<number | string>) { pagesLoaded = val; },
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
                        :imageSrc="item?.preview"
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
    mocks.mockRemove.mockClear();
    mocks.mockRestore.mockClear();

    const tabIndexUrl = tabIndex.definition?.url ?? tabIndex.url();

    mocks.mockAxios.get.mockImplementation((url: string) => {
        const tabShowMatch = url.match(/\/api\/tabs\/(\d+)(?:\?|$)/);
        if (tabShowMatch) {
            const tabId = Number(tabShowMatch[1]);
            return Promise.resolve({
                data: {
                    tab: {
                        id: tabId,
                        label: `Test Tab ${tabId}`,
                        params: {},
                        feed: 'online',
                    },
                },
            });
        }
        if (url.includes(tabIndexUrl)) {
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
            { path: '/dashboard', component: { template: '<div>Dashboard</div>' } }],
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
        params: { service: 'civit-ai-images', page: 1 },
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
    const tabIndexUrl = tabIndex.definition?.url ?? tabIndex.url();
    const browseIndexUrl = browseIndex.definition?.url ?? browseIndex.url();

    mocks.mockAxios.get.mockImplementation((url: string) => {
        const tabShowMatch = url.match(/\/api\/tabs\/(\d+)(?:\?|$)/);
        if (tabShowMatch) {
            const tabId = Number(tabShowMatch[1]);
            const tab = Array.isArray(tabConfig) ? tabConfig.find((t: any) => t.id === tabId) : tabConfig;
            const params = (tab?.params ?? {}) as Record<string, unknown>;
            const feed = (typeof params.feed === 'string' ? params.feed : 'online') as string;
            const items = tab?.items ?? [];
            return Promise.resolve({
                data: {
                    tab: {
                        id: tab?.id ?? tabId,
                        label: tab?.label ?? `Test Tab ${tabId}`,
                        params: params,
                        feed,
                        items, // Backend returns items under tab
                    },
                },
            });
        }
        if (url.includes(tabIndexUrl)) {
            // Return tab configs for initial load without file-related data
            const configs = Array.isArray(tabConfig) ? tabConfig : [tabConfig];
            const tabsForIndex = configs.map((tab: any) => {
                const withoutFileData = { ...tab };
                delete withoutFileData.has_files;
                delete withoutFileData.items;
                return withoutFileData;
            });
            return Promise.resolve({ data: tabsForIndex });
        }
        if (url.includes(browseIndexUrl)) {
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
            preview: `https://picsum.photos/id/${i}/300/200`,
            original: `https://picsum.photos/id/${i}/1200/800`,
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

