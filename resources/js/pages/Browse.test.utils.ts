import { vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createRouter, createMemoryHistory } from 'vue-router';
import { ref } from 'vue';
import Browse from './Browse.vue';
import FileViewer from '../components/FileViewer.vue';

// Mock fetch (no longer used, but keep for compatibility)
global.fetch = vi.fn();

// Mock axios
export const mockAxios = {
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

// Mock lucide-vue-next icons
vi.mock('lucide-vue-next', () => ({
    Loader2: { name: 'Loader2', template: '<div class="loader-icon"></div>', props: ['size', 'class'] },
    AlertTriangle: { name: 'AlertTriangle', template: '<div class="alert-icon"></div>', props: ['size'] },
    Info: { name: 'Info', template: '<div class="info-icon"></div>', props: ['size'] },
    Copy: { name: 'Copy', template: '<div class="copy-icon"></div>', props: ['size', 'class'] },
    RefreshCcw: { name: 'RefreshCcw', template: '<div class="refresh-icon"></div>', props: ['size'] },
    ChevronsLeft: { name: 'ChevronsLeft', template: '<div class="chevrons-left-icon"></div>', props: ['size'] },
    SlidersHorizontal: { name: 'SlidersHorizontal', template: '<div class="sliders-icon"></div>', props: ['size'] },
    X: { name: 'X', template: '<div class="x-icon"></div>', props: ['size', 'class'] },
    Check: { name: 'Check', template: '<div class="check-icon"></div>', props: ['size', 'class'] },
    ChevronDown: { name: 'ChevronDown', template: '<div class="chevron-down-icon"></div>', props: ['size', 'class'] },
    ChevronUp: { name: 'ChevronUp', template: '<div class="chevron-up-icon"></div>', props: ['size', 'class'] },
    ChevronLeft: { name: 'ChevronLeft', template: '<div class="chevron-left-icon"></div>', props: ['size', 'class'] },
    ChevronRight: { name: 'ChevronRight', template: '<div class="chevron-right-icon"></div>', props: ['size', 'class'] },
    Play: { name: 'Play', template: '<div class="play-icon"></div>', props: ['size', 'class'] },
    RotateCw: { name: 'RotateCw', template: '<div class="rotate-cw-icon"></div>', props: ['size', 'class'] },
    ThumbsDown: { name: 'ThumbsDown', template: '<div class="thumbs-down-icon"></div>', props: ['size', 'class'] },
    ThumbsUp: { name: 'ThumbsUp', template: '<div class="thumbs-up-icon"></div>', props: ['size', 'class'] },
    Heart: { name: 'Heart', template: '<div class="heart-icon"></div>', props: ['size', 'class'] },
    Laugh: { name: 'Laugh', template: '<div class="laugh-icon"></div>', props: ['size', 'class'] },
    Star: { name: 'Star', template: '<div class="star-icon"></div>', props: ['size', 'class'] },
    Shield: { name: 'Shield', template: '<div class="shield-icon"></div>', props: ['size', 'class'] },
    Plus: { name: 'Plus', template: '<div class="plus-icon"></div>', props: ['size', 'class'] },
    Trash2: { name: 'Trash2', template: '<div class="trash-icon"></div>', props: ['size', 'class'] },
    GripVertical: { name: 'GripVertical', template: '<div class="grip-icon"></div>', props: ['size', 'class'] },
    Save: { name: 'Save', template: '<div class="save-icon"></div>', props: ['size', 'class'] },
    Download: { name: 'Download', template: '<div class="download-icon"></div>', props: ['size', 'class'] },
    Maximize2: { name: 'Maximize2', template: '<div class="maximize-icon"></div>', props: ['size', 'class'] },
    Minimize2: { name: 'Minimize2', template: '<div class="minimize-icon"></div>', props: ['size', 'class'] },
    ExternalLink: { name: 'ExternalLink', template: '<div class="external-link-icon"></div>', props: ['size', 'class'] },
    MoreHorizontal: { name: 'MoreHorizontal', template: '<div class="more-icon"></div>', props: ['size', 'class'] },
    Undo2: { name: 'Undo2', template: '<div class="undo-icon"></div>', props: ['size', 'class'] },
    Pause: { name: 'Pause', template: '<div class="pause-icon"></div>', props: ['size', 'class'] },
    Menu: { name: 'Menu', template: '<div class="menu-icon"></div>', props: ['size', 'class'] },
}));

// Mock @wyxos/vibe
export const mockIsLoading = ref(false);
export const mockCancelLoad = vi.fn();
export const mockDestroy = vi.fn();
export const mockInit = vi.fn();
export const mockRemove = vi.fn();
export const mockRemoveMany = vi.fn();
export const mockRestore = vi.fn();
export const mockRestoreMany = vi.fn();

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
        props: ['items', 'getNextPage', 'layout', 'layoutMode', 'mobileBreakpoint', 'skipInitialLoad', 'backfillEnabled', 'backfillDelayMs', 'backfillMaxCalls'],
        emits: ['backfill:start', 'backfill:tick', 'backfill:stop', 'backfill:retry-start', 'backfill:retry-tick', 'backfill:retry-stop', 'update:items'],
        setup(props: { items: any[] }, { emit }: { emit: (event: string, value: any) => void }) {
            const removeFn = (item: any) => {
                mockRemove(item);
                const index = props.items.findIndex((i: any) => i.id === item.id);
                if (index !== -1) {
                    props.items.splice(index, 1);
                    emit('update:items', props.items);
                }
            };

            const remove = removeFn;

            const removeMany = (itemsToRemove: any[]) => {
                mockRemoveMany(itemsToRemove);
                const ids = new Set(itemsToRemove.map((i: any) => i.id));
                const filtered = props.items.filter((i: any) => !ids.has(i.id));
                props.items.splice(0, props.items.length, ...filtered);
                emit('update:items', props.items);
            };

            const restore = (item: any, index: number) => {
                mockRestore(item, index);
                const existingIndex = props.items.findIndex((i: any) => i.id === item.id);
                if (existingIndex === -1) {
                    const targetIndex = Math.min(index, props.items.length);
                    props.items.splice(targetIndex, 0, item);
                    emit('update:items', props.items);
                }
            };

            const restoreMany = (itemsToRestore: any[], indices: number[]) => {
                mockRestoreMany(itemsToRestore, indices);
                const existingIds = new Set(props.items.map((i: any) => i.id));
                const itemsToAdd = itemsToRestore.filter((item: any, i: number) => !existingIds.has(item.id));

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

// Setup beforeEach for all test files
export function setupBrowseTestMocks() {
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
        mockAxios.get.mockImplementation((url: string) => {
            if (url.includes('/api/tabs')) {
                return Promise.resolve({ data: [] });
            }
            return Promise.resolve({ data: { items: [], nextPage: null } });
        });

        // Default mock for patch (setActive) - resolves successfully
        mockAxios.patch.mockResolvedValue({ data: {} });
    });
}

// Mock usePreviewBatch composable
export const mockQueuePreviewIncrement = vi.fn();
vi.mock('@/composables/usePreviewBatch', () => ({
    usePreviewBatch: () => ({
        queuePreviewIncrement: mockQueuePreviewIncrement,
    }),
}));

// Helper functions
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

export function getTabContent(wrapper: any) {
    const tabContent = wrapper.findComponent({ name: 'TabContent' });
    if (tabContent.exists()) {
        return tabContent.vm;
    }
    return null;
}

export function getFileViewer(wrapper: any) {
    const tabContent = wrapper.findComponent({ name: 'TabContent' });
    if (tabContent.exists()) {
        const fileViewer = tabContent.findComponent(FileViewer);
        if (fileViewer.exists()) {
            return fileViewer;
        }
    }
    return null;
}

export function createMockBrowseResponse(
    page: number | string,
    nextPageValue: number | string | null = null
) {
    const pageNum = typeof page === 'number' ? page : 1;
                const items = Array.from({ length: 40 }, () => {
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

export function createMockTabConfig(tabId: number, overrides: Record<string, any> = {}) {
    return {
        id: tabId,
        label: `Test Tab ${tabId}`,
        query_params: { service: 'civit-ai-images', page: 1 },
        file_ids: [],
        items: [],
        position: 0,
        is_active: false,
        ...overrides,
    };
}

export function setupAxiosMocks(tabConfig: any | any[], browseResponse?: any) {
    mockAxios.get.mockImplementation((url: string) => {
        if (url.includes('/api/tabs')) {
            return Promise.resolve({ data: Array.isArray(tabConfig) ? tabConfig : [tabConfig] });
        }
        if (url.includes('/api/tabs/') && url.includes('/items')) {
            const tabId = url.match(/\/api\/browse-tabs\/(\d+)\/items/)?.[1];
            const tab = Array.isArray(tabConfig) ? tabConfig.find((t: any) => t.id === Number(tabId)) : tabConfig;
            if (tab && tab.items) {
                return Promise.resolve({
                    data: {
                        items: tab.items,
                    },
                });
            }
            return Promise.resolve({ data: { items: [] } });
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

export async function mountBrowseWithTab(tabConfig: any | any[], browseResponse?: any) {
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

export async function waitForStable(wrapper: any, iterations = 2): Promise<void> {
    for (let i = 0; i < iterations; i++) {
        await flushPromises();
        await wrapper.vm.$nextTick();
    }
}

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
    await new Promise(resolve => setTimeout(resolve, 550));
}

export async function waitForOverlayClose(fileViewerVm: any, timeout = 1000): Promise<void> {
    await waitForOverlayAnimation(
        fileViewerVm,
        () => fileViewerVm.overlay?.overlayRect?.value === null,
        timeout
    );
}

export async function waitForOverlayFill(fileViewerVm: any, timeout = 1000): Promise<void> {
    await waitForOverlayAnimation(
        fileViewerVm,
        () => fileViewerVm.overlay?.overlayFillComplete?.value === true,
        timeout
    );
}

export async function waitForNavigation(fileViewerVm: any, timeout = 1000): Promise<void> {
    await waitForOverlayAnimation(
        fileViewerVm,
        () => fileViewerVm.overlay?.isNavigating?.value === false,
        timeout
    );
}

export async function setupOverlayTest() {
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
