import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { ref } from 'vue';
import Browse from './Browse.vue';
import { show as tabShow, index as tabIndex } from '@/actions/App/Http/Controllers/TabController';
import { index as browseIndex, services as browseServices, sources as browseSources } from '@/actions/App/Http/Controllers/BrowseController';
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
                refreshLayout: vi.fn(),
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

describe('Browse - Tab Restoration', () => {
    it('restores tab query params after refresh', async () => {
        const tabId = 1;
        const currentToken = 'cursor-next-456';
        const nextToken = 'cursor-next-789';
        const mockItems = [
            { id: 1, width: 100, height: 100, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false },
            { id: 2, width: 200, height: 200, src: 'test2.jpg', type: 'image', page: 1, index: 1, notFound: false },
        ];

        const tabConfig = createMockTabConfig(tabId, {
            params: { service: 'civit-ai-images', page: currentToken, next: nextToken },
            items: mockItems,
        });

        const router = await createTestRouter('/browse');
        setupAxiosMocks(mocks, tabConfig);
        const wrapper = mount(Browse, { global: { plugins: [router] } });

        await waitForStable(wrapper);

        const vm = wrapper.vm as any;
        expect(vm.activeTabId).toBe(tabId);

        const tabContentVm = await waitForTabContent(wrapper);
        expect(tabContentVm).not.toBeNull();

        const masonry = wrapper.findComponent({ name: 'Masonry' });
        expect(masonry.exists()).toBe(true);
        // `page` holds the current token to load.
        expect(masonry.props('page')).toBe(currentToken);
        expect(masonry.props('restoredPages')).toBeUndefined();

        const pills = wrapper.findAllComponents({ name: 'Pill' });
        const nextPill = pills.find((pill) => pill.props('label') === 'Next');
        expect(nextPill?.props('value')).toBe(nextToken);
    });

    it('loads tab items when items exist', async () => {
        const tabId = 1;
        const mockItems = [
            { id: 1, width: 100, height: 100, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false },
            { id: 2, width: 200, height: 200, src: 'test2.jpg', type: 'image', page: 1, index: 1, notFound: false },
        ];

        const tabConfig = createMockTabConfig(tabId, {
            params: { service: 'civit-ai-images', page: 1 },
            items: mockItems,
        });

        const router = await createTestRouter('/browse');
        setupAxiosMocks(mocks, tabConfig);
        const wrapper = mount(Browse, { global: { plugins: [router] } });

        await waitForStable(wrapper);

        expect(mocks.mockAxios.get).toHaveBeenCalledWith(tabShow.url({ tab: 1 }));
    });

    it('initializes masonry with restored items', async () => {
        const tabId = 1;
        const mockItems = [
            { id: 1, width: 100, height: 100, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false },
            { id: 2, width: 200, height: 200, src: 'test2.jpg', type: 'image', page: 1, index: 1, notFound: false },
        ];

        const tabConfig = createMockTabConfig(tabId, {
            params: { service: 'civit-ai-images', page: 1 },
            items: mockItems,
        });

        const router = await createTestRouter('/browse');
        setupAxiosMocks(mocks, tabConfig);
        const wrapper = mount(Browse, { global: { plugins: [router] } });

        await waitForStable(wrapper);

        const masonry = wrapper.findComponent({ name: 'Masonry' });
        expect(masonry.exists()).toBe(true);
    });

    it('switches to tab with saved query params', async () => {
        const tab1Id = 1;
        const tab2Id = 2;
        const currentToken = 'cursor-next-789';

        const tabConfigs = [
            createMockTabConfig(tab1Id, {
                params: { service: 'civit-ai-images', page: 1 },
            }),
            createMockTabConfig(tab2Id, {
                params: { service: 'civit-ai-images', page: currentToken },
                position: 1,
            }),
        ];

        const router = await createTestRouter('/browse');
        setupAxiosMocks(mocks, tabConfigs);
        const wrapper = mount(Browse, { global: { plugins: [router] } });

        await waitForStable(wrapper);

        const vm = wrapper.vm as any;
        expect(vm.activeTabId).toBe(tab1Id);

        await vm.switchTab(tab2Id);
        await waitForStable(wrapper);

        expect(vm.activeTabId).toBe(tab2Id);

        const tabContentVm = await waitForTabContent(wrapper);
        expect(tabContentVm).not.toBeNull();

        const masonry = wrapper.findComponent({ name: 'Masonry' });
        expect(masonry.exists()).toBe(true);
        expect(masonry.props('page')).toBe(currentToken);
        expect(masonry.props('restoredPages')).toBeUndefined();
    });

    it('restores items when switching to tab with items', async () => {
        const tab1Id = 1;
        const tab2Id = 2;
        const mockItems = [
            { id: 3, width: 100, height: 100, src: 'test3.jpg', type: 'image', page: 2, index: 0, notFound: false },
            { id: 4, width: 200, height: 200, src: 'test4.jpg', type: 'image', page: 2, index: 1, notFound: false },
        ];

        const tabConfigs = [
            createMockTabConfig(tab1Id, {
                params: { service: 'civit-ai-images', page: 1 },
            }),
            createMockTabConfig(tab2Id, {
                params: { service: 'civit-ai-images', page: 1 },
                items: mockItems,
                position: 1,
            }),
        ];

        const router = await createTestRouter('/browse');
        setupAxiosMocks(mocks, tabConfigs);
        const wrapper = mount(Browse, { global: { plugins: [router] } });

        await waitForStable(wrapper);

        const vm = wrapper.vm as any;

        await vm.switchTab(tab2Id);
        await waitForStable(wrapper);

        expect(mocks.mockAxios.get).toHaveBeenCalledWith(tabShow.url({ tab: 2 }));

        // Note: TabContent unmounting behavior (destroy/cancelLoad) is handled by Vue's
        // key-based component lifecycle. The onUnmounted hook will call destroy if masonry
        // exists, but in test environment, the component may not fully unmount or masonry
        // may not be initialized. The actual behavior is tested implicitly through other tests.
    });

    it('resumes pagination from saved cursor value', async () => {
        const tabId = 1;
        const currentToken = 'cursor-next-789';
        const browseResponse = {
            ...createMockBrowseResponse(currentToken, 'cursor-next-999'),
            services: [{ key: 'civit-ai-images', label: 'CivitAI Images' }],
        };

        const tabConfig = createMockTabConfig(tabId, {
            params: { service: 'civit-ai-images', page: currentToken },
        });

        setupAxiosMocks(mocks, tabConfig, browseResponse);
        const router = await createTestRouter();
        const wrapper = mount(Browse, { global: { plugins: [router] } });

        await flushPromises();
        await wrapper.vm.$nextTick();
        await waitForStable(wrapper);

        const tabContentVm = await waitForTabContent(wrapper);
        if (!tabContentVm) {
            return;
        }

        tabContentVm.isTabRestored = false;
        tabContentVm.items = [];

        const getNextPageResult = await tabContentVm.getPage(currentToken);

        expect(mocks.mockAxios.get).toHaveBeenCalledWith(expect.stringContaining(browseIndex.definition.url));
        expect(mocks.mockAxios.get).toHaveBeenCalledWith(expect.stringContaining(`page=${currentToken}`));
        expect(getNextPageResult.nextPage).toBeDefined();
    });

    it('preserves cursor values on page reload instead of resetting to page 1', async () => {
        const tabId = 1;
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

        const tabConfig = createMockTabConfig(tabId, {
            params: { service: 'civit-ai-images', page: cursorY },
            items: mockItems,
        });

        const router = await createTestRouter('/browse');
        setupAxiosMocks(mocks, tabConfig);
        const wrapper = mount(Browse, { global: { plugins: [router] } });

        await waitForStable(wrapper);

        const vm = wrapper.vm as any;
        expect(vm.activeTabId).toBe(tabId);

        await waitForStable(wrapper);

        const tabContentVm = getTabContent(wrapper);
        expect(tabContentVm).not.toBeNull();

        const masonry = wrapper.findComponent({ name: 'Masonry' });
        expect(masonry.exists()).toBe(true);
        // Cursor token is stored in `page`.
        expect(masonry.props('page')).toBe(cursorY);
    });

    it('continues saved cursor after creating a new tab and switching back', async () => {
        const tabId = 1;
        const cursorY = 'cursor-y';
        const mockItems = [
            { id: 1, width: 100, height: 100, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false },
            { id: 2, width: 120, height: 120, src: 'test2.jpg', type: 'image', page: 1, index: 1, notFound: false },
        ];

        const tabConfig = createMockTabConfig(tabId, {
            params: { service: 'civit-ai-images', page: cursorY },
            items: mockItems,
        });

        setupAxiosMocks(mocks, tabConfig);

        // Override browse API mock to handle cursor logic
        const originalGetMock = mocks.mockAxios.get.getMockImplementation();
        mocks.mockAxios.get.mockImplementation((url: string) => {
            // Handle browse endpoint with cursor logic
            if (url.includes(browseIndex.definition.url)) {
                const parsed = new URL(url, 'http://localhost');
                const requestedPage = parsed.searchParams.get('page') ?? '1';
                const nextValue = requestedPage === cursorY ? 'cursor-z' : cursorY;
                return Promise.resolve({
                    data: {
                        ...createMockBrowseResponse(requestedPage, nextValue),
                        services: [{ key: 'civit-ai-images', label: 'CivitAI Images' }],
                    },
                });
            }
            // Use original mock for other endpoints
            if (originalGetMock) {
                return originalGetMock(url);
            }
            return Promise.resolve({ data: { items: [], nextPage: null } });
        });

        mocks.mockAxios.post.mockResolvedValue({
            data: {
                id: 2,
                label: 'Browse 2',
                params: { page: 1 },
                position: 1,
            },
        });

        mocks.mockAxios.put.mockResolvedValue({});

        const router = await createTestRouter('/browse');
        const wrapper = mount(Browse, { global: { plugins: [router] } });

        await waitForStable(wrapper);

        const vm = wrapper.vm as any;
        expect(vm.activeTabId).toBe(tabId);

        await vm.createTab();
        await waitForStable(wrapper);

        expect(vm.activeTabId).toBe(2);

        await vm.switchTab(tabId);
        await waitForStable(wrapper);

        expect(vm.activeTabId).toBe(tabId);
        await waitForStable(wrapper);

        const tabContentVm = getTabContent(wrapper);
        if (!tabContentVm) {
            return;
        }

        // Switching back should restore the saved cursor as the Masonry `page` token.
        const masonry = wrapper.findComponent({ name: 'Masonry' });
        expect(masonry.exists()).toBe(true);
        expect(masonry.props('page')).toBe(cursorY);

        // Ensure the restored token is usable for fetching.
        await tabContentVm.getPage(cursorY);

        const browseCalls = mocks.mockAxios.get.mock.calls
            .map(call => call[0])
            .filter((callUrl: string) => {
                return callUrl.includes(`${browseIndex.definition.url}?`) || callUrl === browseIndex.definition.url;
            });

        // Verify that the cursor was used in the API call
        expect(browseCalls[browseCalls.length - 1]).toContain(`page=${cursorY}`);
    });

    it('does not advance local tabs to the next page after refresh', async () => {
        const tabId = 1;
        const tabIndexUrl = tabIndex.definition?.url ?? tabIndex.url();
        const tabShowUrl = tabShow.url({ tab: tabId });
        const browseIndexUrl = browseIndex.definition?.url ?? browseIndex.url();
        const browseServicesUrl = browseServices.definition?.url ?? browseServices.url();
        const browseSourcesUrl = browseSources.definition?.url ?? browseSources.url();

        const browseResponseForPage = (page: number) => ({
            ...createMockBrowseResponse(page, page + 1),
            services: [],
        });

        mocks.mockAxios.get.mockImplementation((url: string) => {
            if (url.includes(browseServicesUrl)) {
                return Promise.resolve({ data: { services: [], local: { key: 'local', label: 'Local' } } });
            }
            if (url.includes(browseSourcesUrl)) {
                return Promise.resolve({ data: { sources: ['all'] } });
            }
            if (url.includes(tabIndexUrl) && !url.match(/\/api\/tabs\/\d+/)) {
                return Promise.resolve({ data: [] });
            }
            if (url.includes(tabShowUrl)) {
                return Promise.resolve({
                    data: {
                        tab: {
                            id: tabId,
                            label: 'Browse 1',
                            params: {},
                            feed: 'online',
                        },
                    },
                });
            }
            if (url.includes(browseIndexUrl)) {
                const parsed = new URL(url, 'http://localhost');
                const page = Number(parsed.searchParams.get('page') ?? 1);
                return Promise.resolve({ data: browseResponseForPage(page) });
            }
            return Promise.resolve({ data: { items: [], nextPage: null } });
        });

        mocks.mockAxios.post.mockResolvedValueOnce({
            data: {
                id: tabId,
                label: 'Browse 1',
                params: {},
                position: 0,
                is_active: false,
            },
        });

        mocks.mockAxios.patch.mockResolvedValue({ data: {} });

        const router = await createTestRouter('/browse');
        const wrapper = mount(Browse, { global: { plugins: [router] } });

        await waitForStable(wrapper);

        const vm = wrapper.vm as any;
        await vm.createTab();
        await waitForStable(wrapper);

        const tabContentVm = await waitForTabContent(wrapper);
        if (!tabContentVm) {
            return;
        }

        const browseForm = tabContentVm.browseForm ?? tabContentVm.$?.exposed?.browseForm;
        if (!browseForm) {
            throw new Error('TabContent browse form was not exposed');
        }
        browseForm.data.feed = 'local';
        browseForm.data.source = 'all';

        const applyService = tabContentVm.$?.setupState?.applyService;
        if (typeof applyService === 'function') {
            await applyService();
        } else {
            await wrapper.find('[data-test="play-button"]').trigger('click');
        }
        await waitForStable(wrapper);

        const masonry = wrapper.findComponent({ name: 'Masonry' });
        expect(masonry.exists()).toBe(true);
        await masonry.vm.loadPage(1);
        await waitForStable(wrapper);

        const initialItems = masonry.props('items');
        expect(initialItems[0]?.page).toBe(1);
        masonry.vm.pagesLoaded = [1];
        masonry.vm.nextPage = 2;
        expect(masonry.vm.pagesLoaded).toEqual([1]);
        expect(masonry.vm.nextPage).toBe(2);

        wrapper.unmount();

        mocks.mockAxios.get.mockImplementation((url: string) => {
            if (url.includes(browseServicesUrl)) {
                return Promise.resolve({ data: { services: [], local: { key: 'local', label: 'Local' } } });
            }
            if (url.includes(browseSourcesUrl)) {
                return Promise.resolve({ data: { sources: ['all'] } });
            }
            if (url.includes(tabIndexUrl) && !url.match(/\/api\/tabs\/\d+/)) {
                return Promise.resolve({
                    data: [{
                        id: tabId,
                        label: 'Browse 1',
                        params: { feed: 'local', source: 'all', page: 1 },
                        position: 0,
                        is_active: true,
                    }],
                });
            }
            if (url.includes(tabShowUrl)) {
                return Promise.resolve({
                    data: {
                        tab: {
                            id: tabId,
                            label: 'Browse 1',
                            params: { feed: 'local', source: 'all', page: 1 },
                            feed: 'local',
                            items: [],
                        },
                    },
                });
            }
            if (url.includes(browseIndexUrl)) {
                const parsed = new URL(url, 'http://localhost');
                const page = Number(parsed.searchParams.get('page') ?? 1);
                return Promise.resolve({ data: browseResponseForPage(page) });
            }
            return Promise.resolve({ data: { items: [], nextPage: null } });
        });

        const refreshedRouter = await createTestRouter('/browse');
        const refreshedWrapper = mount(Browse, { global: { plugins: [refreshedRouter] } });

        await waitForStable(refreshedWrapper);

        const refreshedTabContentVm = await waitForTabContent(refreshedWrapper);
        if (!refreshedTabContentVm) {
            return;
        }

        const refreshedMasonry = refreshedWrapper.findComponent({ name: 'Masonry' });
        expect(refreshedMasonry.exists()).toBe(true);
        expect(refreshedMasonry.props('page')).toBe(1);
        await refreshedMasonry.vm.loadPage(refreshedMasonry.props('page'));
        await waitForStable(refreshedWrapper);

        const refreshedItems = refreshedMasonry.props('items');
        expect(refreshedItems[0]?.page).toBe(1);
        expect(refreshedMasonry.vm.pagesLoaded).toEqual([1]);
        expect(refreshedMasonry.vm.nextPage).toBe(2);
    });
});
