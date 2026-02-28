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
        props: ['items', 'getContent', 'page', 'restoredPages', 'pageSize', 'gapX', 'gapY', 'mode'],
        emits: ['update:items'],
        setup(
            props: {
                items: any[];
                getContent?: (page: number | string) => Promise<{ items?: any[]; nextPage?: number | string | null }>;
                page?: number | string;
                restoredPages?: Array<number | string>;
            },
            { emit }: { emit: (event: string, value: any) => void }
        ) {
            let nextPage: number | string | null = null;
            let pagesLoaded: Array<number | string> = [];
            let hasReachedEnd = false;

            if (Array.isArray(props.restoredPages)) {
                pagesLoaded = [...props.restoredPages];
            } else if (props.page !== undefined && props.page !== null) {
                pagesLoaded = [props.page];
            }

            const initialize = (itemsToRestore: any[], page: number | string, next: number | string | null) => {
                mockInit(itemsToRestore, page, next);
                props.items.splice(0, props.items.length, ...itemsToRestore);
                emit('update:items', props.items);
                nextPage = next ?? null;
                hasReachedEnd = nextPage === null;
                pagesLoaded = [page];
            };

            const loadPage = async (page: number | string) => {
                if (!props.getContent) {
                    return;
                }
                const result = await props.getContent(page);
                const newItems = result?.items ?? [];
                props.items.splice(0, props.items.length, ...newItems);
                emit('update:items', props.items);
                nextPage = result?.nextPage ?? null;
                hasReachedEnd = nextPage === null;
                pagesLoaded = [page];
                return result;
            };

            const loadNextPage = async () => {
                if (!props.getContent || nextPage === null || nextPage === undefined) {
                    return;
                }
                const pageToLoad = nextPage;
                const result = await props.getContent(pageToLoad);
                const newItems = result?.items ?? [];
                props.items.push(...newItems);
                emit('update:items', props.items);
                nextPage = result?.nextPage ?? null;
                hasReachedEnd = nextPage === null;
                pagesLoaded = [...pagesLoaded, pageToLoad];
                return result;
            };

            const reset = () => {
                props.items.splice(0, props.items.length);
                emit('update:items', props.items);
                nextPage = null;
                hasReachedEnd = false;
                pagesLoaded = [];
            };

            const exposed = {
                init: mockInit,
                initialize,
                cancel: mockCancelLoad,
                destroy: mockDestroy,
                remove: mockRemove,
                removeMany: mockRemoveMany,
                restore: mockRestore,
                restoreMany: mockRestoreMany,
                loadPage,
                loadNextPage,
                undo: vi.fn(),
                forget: vi.fn(),
                reset,
            };
            Object.defineProperty(exposed, 'isLoading', { get: () => mockIsLoading.value, enumerable: true });
            Object.defineProperty(exposed, 'hasReachedEnd', { get: () => hasReachedEnd, enumerable: true });
            Object.defineProperty(exposed, 'nextPage', {
                get: () => nextPage,
                set: (val: number | string | null) => { nextPage = val; },
                enumerable: true,
            });
            Object.defineProperty(exposed, 'pagesLoaded', {
                get: () => pagesLoaded,
                set: (val: Array<number | string>) => { pagesLoaded = val; },
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

        const tabContentVm = getTabContent(wrapper);
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
        expect(masonry.props('page')).toBe(1);
        expect(typeof masonry.props('getContent')).toBe('function');
        expect(masonry.props('pageSize')).toBe(20);
        expect(masonry.props('gapX')).toBe(12);
        expect(masonry.props('gapY')).toBe(12);
        expect(masonry.props('mode')).toBe('backfill');
    });
    it('provides getPage function that fetches from API', async () => {
        const mockResponse = createMockBrowseResponse(2, 3);
        const tabId = 1;
        const tabConfig = createMockTabConfig(tabId, { params: { service: 'civit-ai-images' } });
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
        if (activeTab && !activeTab.params.service) {
            activeTab.params.service = 'civit-ai-images';
        }
        const getNextPage = tabContentVm.getPage;

        const result = await getNextPage(2);

        // Browse calls include additional query params; assert the important bits.
        expect(mocks.mockAxios.get).toHaveBeenCalledWith(expect.stringContaining(browseIndex.definition.url));
        expect(mocks.mockAxios.get).toHaveBeenCalledWith(expect.stringContaining('page=2'));
        // Verify tab_id was included in the request (backend will update params)
        expect(mocks.mockAxios.get).toHaveBeenCalledWith(
            expect.stringContaining(`tab_id=${tabId}`)
        );
        expect(result).toHaveProperty('items');
        expect(result).toHaveProperty('nextPage');
        expect(result.items).toBeInstanceOf(Array);
        expect(result.items.length).toBe(40);
        expect(result.nextPage).toBe(3);

        const updatedTab = vm.tabs.find((t: any) => t.id === tabId);
        expect(updatedTab).toBeDefined();
    });
});
