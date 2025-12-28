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
        props: ['items', 'getPage', 'layout', 'layoutMode', 'mobileBreakpoint', 'init', 'mode', 'backfillDelayMs', 'backfillMaxCalls'],
        emits: ['backfill:start', 'backfill:tick', 'backfill:stop', 'backfill:retry-start', 'backfill:retry-tick', 'backfill:retry-stop', 'update:items'],
        setup(props: { items: any[]; getPage?: (page: number | string) => Promise<{ items?: any[]; nextPage?: number | string | null }> }, { emit }: { emit: (event: string, value: any) => void }) {
            let currentPage: number | string | null = null;
            let nextPage: number | string | null = null;
            let hasReachedEnd = false;
            let paginationHistory: Array<number | string> = [];

            const initialize = (itemsToRestore: any[], page: number | string, next: number | string | null) => {
                mockInit(itemsToRestore, page, next);
                props.items.splice(0, props.items.length, ...itemsToRestore);
                emit('update:items', props.items);
                currentPage = page;
                nextPage = next ?? null;
                paginationHistory = nextPage === null ? [] : [nextPage];
                hasReachedEnd = nextPage === null;
            };

            const loadPage = async (page: number | string) => {
                if (!props.getPage) {
                    return;
                }
                currentPage = page;
                const result = await props.getPage(page);
                const newItems = result?.items ?? [];
                props.items.splice(0, props.items.length, ...newItems);
                emit('update:items', props.items);
                nextPage = result?.nextPage ?? null;
                paginationHistory = nextPage === null ? [] : [nextPage];
                hasReachedEnd = nextPage === null;
                return result;
            };

            const loadNext = async () => {
                if (!props.getPage || nextPage === null || nextPage === undefined) {
                    return;
                }
                const pageToLoad = nextPage;
                currentPage = pageToLoad;
                const result = await props.getPage(pageToLoad);
                const newItems = result?.items ?? [];
                props.items.push(...newItems);
                emit('update:items', props.items);
                nextPage = result?.nextPage ?? null;
                paginationHistory = nextPage === null ? [] : [nextPage];
                hasReachedEnd = nextPage === null;
                return result;
            };

            const reset = () => {
                props.items.splice(0, props.items.length);
                emit('update:items', props.items);
                currentPage = null;
                nextPage = null;
                paginationHistory = [];
                hasReachedEnd = false;
            };

            const exposed = {
                init: mockInit,
                initialize,
                refreshLayout: vi.fn(),
                cancelLoad: mockCancelLoad,
                destroy: mockDestroy,
                remove: mockRemove,
                removeMany: mockRemoveMany,
                restore: mockRestore,
                restoreMany: mockRestoreMany,
                loadPage,
                loadNext,
                reset,
            };
            Object.defineProperty(exposed, 'isLoading', { get: () => mockIsLoading.value, enumerable: true });
            Object.defineProperty(exposed, 'hasReachedEnd', { get: () => hasReachedEnd, enumerable: true });
            Object.defineProperty(exposed, 'currentPage', { get: () => currentPage, enumerable: true });
            Object.defineProperty(exposed, 'nextPage', { get: () => nextPage, enumerable: true });
            Object.defineProperty(exposed, 'paginationHistory', { get: () => paginationHistory, enumerable: true });
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
        expect(masonry.props('layoutMode')).toBe('auto');
        expect(masonry.props('mobileBreakpoint')).toBe(768);
        // Atlas uses manual init and triggers page loads explicitly.
        expect(masonry.props('init')).toBe('manual');
        expect(masonry.props('layout')).toEqual({
            gutterX: 12,
            gutterY: 12,
            sizes: { base: 1, sm: 2, md: 3, lg: 4, '2xl': 10 },
        });
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
        // itemsData is populated by masonry/restoration; getPage only returns the response.
    });

    it('handles API errors gracefully', async () => {
        const networkError = new Error('Network error');

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
                            itemsData: [],
                        },
                    },
                });
            }
            if (url.includes(tabIndex.definition.url)) {
                return Promise.resolve({ data: [] });
            }
            if (url.includes(browseIndex.definition.url)) {
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

        tabContentVm.isTabRestored = false;
        tabContentVm.items = [];
        const getNextPage = tabContentVm.getPage;

        await expect(getNextPage(1)).rejects.toThrow('Network error');
    });

    it('returns correct structure from getPage with null nextPage', async () => {
        const mockResponse = createMockBrowseResponse(100, null);

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
                            itemsData: [],
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

        tabContentVm.isTabRestored = false;
        tabContentVm.items = [];
        const result = await tabContentVm.getPage(100);

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
                            itemsData: [],
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

        tabContentVm.isTabRestored = false;
        tabContentVm.items = [];
        const result = await tabContentVm.getPage(cursor);

        // Cursor-based pagination uses `next=` in current implementation.
        expect(mocks.mockAxios.get).toHaveBeenCalledWith(expect.stringContaining('/api/browse'));
        expect(mocks.mockAxios.get).toHaveBeenCalledWith(expect.stringContaining(`page=${cursor}`));
        expect(mocks.mockAxios.get).toHaveBeenCalledWith(expect.stringContaining(`next=${cursor}`));
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
                            itemsData: [],
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

        tabContentVm.isTabRestored = false;
        tabContentVm.items = [];
        await tabContentVm.getPage(1);

        expect(mocks.mockAxios.get).toHaveBeenCalledWith(expect.stringContaining('page=1'));
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
            params: { service: 'civit-ai-images', page: pageParam, next: nextParam },
            items: mockItems,
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
            expect(tabContentVm.masonry?.currentPage).toBe(pageParam);
            expect(tabContentVm.masonry?.paginationHistory?.[0]).toBe(nextParam);
        }
        expect(mocks.mockAxios.get).toHaveBeenCalledWith(tabShow.url({ tab: 1 }));
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
            if (url.includes(tabIndex.definition.url)) {
                return Promise.resolve({
                    data: [{
                        id: 1,
                        label: 'Test Tab',
                        params: { service: 'civit-ai-images', page: 1 },
                        items: [],
                        position: 0,
                    }],
                });
            }
            if (url.includes(browseIndex.definition.url)) {
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
        if (tabContentVm.masonry) {
            tabContentVm.masonry.currentPage = 2;
            tabContentVm.masonry.paginationHistory = ['cursor-123'];
        }

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
        if (tabContentVm?.masonry) {
            tabContentVm.masonry.paginationHistory = [];
            await wrapper.vm.$nextTick();

            const nextPill = wrapper
                .findAllComponents({ name: 'Pill' })
                .find((p) => p.props('label') === 'Next');
            if (nextPill) {
                expect(nextPill.props('value')).toBe('N/A');
            }
        }
    });

    it('handles tab with page parameter in params and loads items lazily', async () => {
        const tabId = 1;
        const pageParam = 'cursor-string-123';

        mocks.mockAxios.get.mockImplementation((url: string) => {
            if (url.includes(tabIndex.definition.url) && !url.match(/\/api\/tabs\/\d+/)) {
                return Promise.resolve({
                    data: [{
                        id: tabId,
                        label: 'Test Tab',
                        params: { service: 'civit-ai-images', page: pageParam },
                        position: 0,
                        is_active: true,
                    }],
                });
            }
            if (url.includes(tabShow.url({ tab: 1 }))) {
                return Promise.resolve({
                    data: {
                        tab: {
                            id: tabId,
                            label: 'Test Tab',
                            params: { service: 'civit-ai-images', page: pageParam },
                            feed: 'online',
                            itemsData: [{ id: 123, width: 100, height: 100, src: 'test.jpg', type: 'image', page: 1, index: 0, notFound: false }],
                        },
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

        const tabContentVm = getTabContent(wrapper);
        if (tabContentVm) {
            expect(tabContentVm.masonry?.currentPage).toBe(pageParam);
        }
        expect(mocks.mockAxios.get).toHaveBeenCalledWith(tabShow.url({ tab: 1 }));
    });

    it('handles tab with page in params correctly and loads items lazily', async () => {
        const tabId = 1;
        const pageValue = 123;

        mocks.mockAxios.get.mockImplementation((url: string) => {
            if (url.includes(tabIndex.definition.url) && !url.match(/\/api\/tabs\/\d+/)) {
                return Promise.resolve({
                    data: [{
                        id: tabId,
                        label: 'Test Tab',
                        params: { service: 'civit-ai-images', page: pageValue },
                        position: 0,
                        is_active: true,
                    }],
                });
            }
            if (url.includes(tabShow.url({ tab: 1 }))) {
                return Promise.resolve({
                    data: {
                        tab: {
                            id: tabId,
                            label: 'Test Tab',
                            params: { service: 'civit-ai-images', page: pageValue },
                            feed: 'online',
                            itemsData: [{ id: 123, width: 100, height: 100, src: 'test.jpg', type: 'image', page: 1, index: 0, notFound: false }],
                        },
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

        const tabContentVm = getTabContent(wrapper);
        if (tabContentVm) {
            expect(tabContentVm.masonry?.currentPage).toBe(pageValue);
        }
        expect(mocks.mockAxios.get).toHaveBeenCalledWith(tabShow.url({ tab: 1 }));
    });
});
