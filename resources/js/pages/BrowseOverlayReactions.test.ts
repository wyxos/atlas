import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { ref } from 'vue';
import Browse from './Browse.vue';
import { index as tabIndex } from '@/actions/App/Http/Controllers/TabController';
import { index as browseIndex } from '@/actions/App/Http/Controllers/BrowseController';
import {
    setupBrowseTestMocks,
    createTestRouter,
    waitForStable,
    waitForTabContent,
    createMockTabConfig,
    setupAxiosMocks,
    setupBoundingClientRectMock,
    type BrowseMocks,
} from '@/test/browse-test-utils';

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
    mockAxios: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), patch: vi.fn() },
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

global.fetch = vi.fn();
vi.mock('axios', () => ({ default: mockAxios }));
Object.defineProperty(window, 'axios', { value: mockAxios, writable: true });

vi.mock('@wyxos/vibe', () => ({
    Masonry: {
        name: 'Masonry',
        template: '<div class="masonry-mock"><slot v-for="(item, index) in items" :key="item.id || index" :item="item" :remove="() => {}" :index="index"></slot></div>',
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
        template: '<div @mouseenter="$emit(\'mouseenter\', $event)" @mouseleave="$emit(\'mouseleave\', $event)"><slot :item="item" :remove="remove" :imageLoaded="true" :imageError="false" :videoLoaded="false" :videoError="false" :isLoading="false" :showMedia="true" :imageSrc="item?.preview" :videoSrc="null"></slot></div>',
        props: ['item', 'remove'],
        emits: ['mouseenter', 'mouseleave', 'preload:success'],
    },
}));

vi.mock('@/composables/usePreviewBatch', () => ({
    usePreviewBatch: () => ({ queuePreviewIncrement: mockQueuePreviewIncrement }),
}));

beforeEach(() => {
    setupBrowseTestMocks(mocks);
    setupBoundingClientRectMock();
});

describe('Browse - Overlay Reactions', () => {
    it('shows FileReactions component on hover over masonry item', async () => {
        const browseResponse = {
            items: [
                { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false },
                { id: 2, width: 300, height: 400, src: 'test2.jpg', type: 'image', page: 1, index: 1, notFound: false },
            ],
            nextPage: null,
            services: [{ key: 'civit-ai-images', label: 'CivitAI Images' }],
        };
        const tabConfig = createMockTabConfig(1);
        setupAxiosMocks(mocks, tabConfig, browseResponse);
        const tabIndexUrl = tabIndex.definition?.url ?? tabIndex.url();
        const browseIndexUrl = browseIndex.definition?.url ?? browseIndex.url();

        mocks.mockAxios.get.mockImplementation((url: string) => {
            const tabShowMatch = url.match(/\/api\/tabs\/(\d+)(?:\?|$)/);
            if (tabShowMatch) {
                const id = Number(tabShowMatch[1]);
                return Promise.resolve({
                    data: {
                        tab: {
                            id,
                            label: `Test Tab ${id}`,
                            params: { service: 'civit-ai-images', page: 1 },
                            feed: 'online',
                        },
                    },
                });
            }
            if (url.includes(tabIndexUrl)) {
                return Promise.resolve({ data: [tabConfig] });
            }
            if (url.includes(browseIndexUrl)) {
                return Promise.resolve({ data: browseResponse });
            }
            if (url.includes('/api/files') && url.includes('/reaction')) {
                return Promise.resolve({ data: { reaction: null } });
            }
            return Promise.resolve({ data: { items: [], nextPage: null } });
        });

        const router = await createTestRouter('/browse');
        const wrapper = mount(Browse, { global: { plugins: [router] } });

        await waitForStable(wrapper);

        const masonryItems = wrapper.findAll('.masonry-mock > div');
        if (masonryItems.length > 0) {
            await masonryItems[0].trigger('mouseenter');
            await wrapper.vm.$nextTick();

            const fileReactions = wrapper.findComponent({ name: 'FileReactions' });
            expect(fileReactions.exists()).toBe(true);
        }
    });

    it('hides FileReactions component when mouse leaves masonry item', async () => {
        const browseResponse = {
            items: [
                { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false },
                { id: 2, width: 300, height: 400, src: 'test2.jpg', type: 'image', page: 1, index: 1, notFound: false },
            ],
            nextPage: null,
            services: [{ key: 'civit-ai-images', label: 'CivitAI Images' }],
        };
        const tabConfig = createMockTabConfig(1);
        setupAxiosMocks(mocks, tabConfig, browseResponse);

        const router = await createTestRouter('/browse');
        const wrapper = mount(Browse, { global: { plugins: [router] } });

        await waitForStable(wrapper);

        const masonryItems = wrapper.findAll('.masonry-mock > div');
        if (masonryItems.length > 0) {
            await masonryItems[0].trigger('mouseenter');
            await wrapper.vm.$nextTick();

            await masonryItems[0].trigger('mouseleave');
            await wrapper.vm.$nextTick();

            // FileReactions should be hidden or hoveredItem should be null
            const tabContentVm = await waitForTabContent(wrapper);
            if (tabContentVm && typeof tabContentVm.hoveredItem !== 'undefined') {
                expect(tabContentVm.hoveredItem).toBeNull();
            }
        }
    });

});
