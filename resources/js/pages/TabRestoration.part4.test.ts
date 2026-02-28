/* eslint-disable @typescript-eslint/no-unused-vars */
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

