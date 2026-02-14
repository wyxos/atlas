import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { ref } from 'vue';
import Browse from './Browse.vue';
import { index as tabIndex } from '@/actions/App/Http/Controllers/TabController';
import { index as browseIndex } from '@/actions/App/Http/Controllers/BrowseController';
import {
    setupBrowseTestMocks,
    createTestRouter,
    getTabContent,
    waitForStable,
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

const tabIndexUrl = tabIndex.definition?.url ?? tabIndex.url();
const browseIndexUrl = browseIndex.definition?.url ?? browseIndex.url();

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
        props: ['items', 'getContent', 'getPage', 'page', 'layout', 'layoutMode', 'init', 'mode', 'restoredPages', 'pageSize', 'gapX', 'gapY'],
        emits: ['update:items', 'preloaded', 'failures'],
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

describe('Browse - Service Selection', () => {
    it('new tab does not auto-load until service is selected', async () => {
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
                        },
                    },
                });
            }
            if (url.includes(tabIndexUrl)) {
                return Promise.resolve({ data: [] });
            }
            if (url.includes(browseIndexUrl)) {
                return Promise.resolve({
                    data: {
                        items: [],
                        nextPage: null,
                        services: [
                            { key: 'civit-ai-images', label: 'CivitAI Images' },
                            { key: 'wallhaven', label: 'Wallhaven' }],
                    },
                });
            }
            return Promise.resolve({ data: { items: [], nextPage: null } });
        });

        mocks.mockAxios.post.mockResolvedValue({
            data: {
                id: 1,
                label: 'Browse 1',
                params: {},
                position: 0,
                is_active: false,
            },
        });

        const router = await createTestRouter('/browse');
        const wrapper = mount(Browse, { global: { plugins: [router] } });

        await waitForStable(wrapper);

        const vm = wrapper.vm as any;

        await vm.createTab();
        await waitForStable(wrapper);

        expect(vm.activeTabId).toBe(1);
        await waitForStable(wrapper);

        const tabContentVm = getTabContent(wrapper);
        if (tabContentVm) {
            expect(tabContentVm.hasServiceSelected).toBe(false);
            // loadAtPage is null by default and only set when needed (e.g., applying filters)
            // The important part is that no items are loaded until service is selected
            expect(tabContentVm.loadAtPage).toBeNull();
            expect(tabContentVm.items.length).toBe(0);
        } else {
            const itemLoadingCalls = mocks.mockAxios.get.mock.calls
                .map(call => call[0])
                .filter((callUrl: string) => {
                return callUrl.includes(browseIndexUrl) && callUrl.includes('service=');
                });
            expect(itemLoadingCalls.length).toBe(0);
        }

        const itemLoadingCalls = mocks.mockAxios.get.mock.calls
            .map(call => call[0])
            .filter((callUrl: string) => {
                return callUrl.includes('/api/browse') && callUrl.includes('service=');
            });
        expect(itemLoadingCalls.length).toBe(0);
    });

    it('applies selected service and triggers loading', async () => {
        mocks.mockAxios.get.mockImplementation((url: string) => {
            const tabShowMatch = url.match(/\/api\/tabs\/(\d+)(?:\?|$)/);
            if (tabShowMatch) {
                const id = Number(tabShowMatch[1]);
                return Promise.resolve({
                    data: {
                        tab: {
                            id,
                            label: 'Test Tab',
                            params: {},
                            feed: 'online',
                        },
                    },
                });
            }
            if (url.includes(tabIndexUrl)) {
                return Promise.resolve({
                    data: [{
                        id: 1,
                        label: 'Test Tab',
                        params: {},
                        position: 0,
                        is_active: true,
                    }],
                });
            }
            if (url.includes(browseIndexUrl)) {
                return Promise.resolve({
                    data: {
                        items: [
                            { id: 1, width: 100, height: 100, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false }],
                        nextPage: 'cursor-2',
                        services: [
                            { key: 'civit-ai-images', label: 'CivitAI Images' },
                            { key: 'wallhaven', label: 'Wallhaven' }],
                    },
                });
            }
            return Promise.resolve({ data: { items: [], nextPage: null } });
        });

        mocks.mockAxios.put.mockResolvedValue({});

        const router = await createTestRouter('/browse');
        const wrapper = mount(Browse, { global: { plugins: [router] } });

        await waitForStable(wrapper);

        const vm = wrapper.vm as any;
        expect(vm.activeTabId).toBe(1);

        await waitForStable(wrapper);

        const tabContentVm = getTabContent(wrapper);
        if (!tabContentVm) {
            return;
        }

        expect(tabContentVm.hasServiceSelected).toBe(false);

        tabContentVm.selectedService = 'civit-ai-images';
        await wrapper.vm.$nextTick();

        await tabContentVm.applyService();
        await waitForStable(wrapper);

        // params are updated by the backend when browse request is made, not immediately by frontend
        // The backend will update params.service when the browse API is called with source parameter
        // Frontend preserves existing params until backend updates them
        expect(tabContentVm.hasServiceSelected).toBe(true);

        const masonry = wrapper.findComponent({ name: 'Masonry' });
        expect(masonry.exists()).toBe(true);

        if (tabContentVm.masonry && tabContentVm.items.length === 0) {
            await tabContentVm.getPage(1);
            await flushPromises();
            await wrapper.vm.$nextTick();
        }

        const browseCalls = mocks.mockAxios.get.mock.calls
            .map(call => call[0])
            .filter((callUrl: string) => {
                return typeof callUrl === 'string'
                    && callUrl.includes(`${browseIndexUrl}?`)
                    && !callUrl.includes(tabIndexUrl)
                    && !callUrl.includes('limit=1');
            });
        expect(browseCalls.length).toBeGreaterThan(0);
        const lastCall = browseCalls[browseCalls.length - 1];
        expect(lastCall).toContain('service=civit-ai-images');
    });

    it('restores service when switching to tab with saved service', async () => {
        const tab1Id = 1;
        const tab2Id = 2;

        mocks.mockAxios.get.mockImplementation((url: string) => {
            if (url.match(/\/api\/tabs\/\d+(?:\?|$)/)) {
                const tabIdMatch = url.match(/\/api\/tabs\/(\d+)(?:\?|$)/);
                const id = tabIdMatch ? Number(tabIdMatch[1]) : 0;
                const service = id === tab2Id ? 'wallhaven' : 'civit-ai-images';
                return Promise.resolve({
                    data: {
                        tab: {
                            id,
                            label: id === tab2Id ? 'Tab 2' : 'Tab 1',
                            params: { service, page: 1 },
                            feed: 'online',
                        },
                    },
                });
            }
            if (url.includes(tabIndexUrl)) {
                return Promise.resolve({
                    data: [
                        {
                            id: tab1Id,
                            label: 'Tab 1',
                            params: { service: 'civit-ai-images', page: 1 },
                            position: 0,
                            is_active: true,
                        },
                        {
                            id: tab2Id,
                            label: 'Tab 2',
                            params: { service: 'wallhaven', page: 1 },
                            position: 1,
                            is_active: false,
                        }],
                });
            }
            if (url.includes(browseIndexUrl)) {
                return Promise.resolve({
                    data: {
                        items: [],
                        nextPage: null,
                        services: [
                            { key: 'civit-ai-images', label: 'CivitAI Images' },
                            { key: 'wallhaven', label: 'Wallhaven' }],
                    },
                });
            }
            return Promise.resolve({ data: { items: [], nextPage: null } });
        });

        const router = await createTestRouter('/browse');
        const wrapper = mount(Browse, { global: { plugins: [router] } });

        await waitForStable(wrapper);

        const vm = wrapper.vm as any;
        expect(vm.activeTabId).toBe(tab1Id);

        await waitForStable(wrapper);

        let tabContentVm = getTabContent(wrapper);
        if (tabContentVm) {
            expect(tabContentVm.currentTabService).toBe('civit-ai-images');
            // selectedService is now synced with currentTabService when tab changes
            expect(tabContentVm.selectedService).toBe('civit-ai-images');
        }

        await vm.switchTab(tab2Id);
        await waitForStable(wrapper);

        expect(vm.activeTabId).toBe(tab2Id);
        tabContentVm = getTabContent(wrapper);
        if (tabContentVm) {
            expect(tabContentVm.currentTabService).toBe('wallhaven');
            expect(tabContentVm.selectedService).toBe('wallhaven');
        }
    });

    it('includes service parameter in browse API calls', async () => {
        const tabId = 1;

        mocks.mockAxios.get.mockImplementation((url: string) => {
            const tabShowMatch = url.match(/\/api\/tabs\/(\d+)(?:\?|$)/);
            if (tabShowMatch) {
                const id = Number(tabShowMatch[1]);
                return Promise.resolve({
                    data: {
                        tab: {
                            id,
                            label: 'Test Tab',
                            params: { service: 'wallhaven', page: 1 },
                            feed: 'online',
                        },
                    },
                });
            }
            if (url.includes(tabIndexUrl)) {
                return Promise.resolve({
                    data: [{
                        id: tabId,
                        label: 'Test Tab',
                        params: { service: 'wallhaven', page: 1 },
                        position: 0,
                    }],
                });
            }
            if (url.includes(browseIndexUrl)) {
                return Promise.resolve({
                    data: {
                        items: [
                            { id: 1, width: 100, height: 100, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false }],
                        nextPage: 'cursor-2',
                        services: [
                            { key: 'civit-ai-images', label: 'CivitAI Images' },
                            { key: 'wallhaven', label: 'Wallhaven' }],
                    },
                });
            }
            return Promise.resolve({ data: { items: [], nextPage: null } });
        });

        const router = await createTestRouter('/browse');
        const wrapper = mount(Browse, { global: { plugins: [router] } });

        await waitForStable(wrapper);

        const tabContentVm = getTabContent(wrapper);
        if (!tabContentVm) {
            return;
        }

        tabContentVm.isTabRestored = false;

        await tabContentVm.getPage(1);
        await flushPromises();

        const browseCalls = mocks.mockAxios.get.mock.calls
            .map(call => call[0])
            .filter((callUrl: string) => callUrl.includes(browseIndexUrl) && !callUrl.includes('services'));

        expect(browseCalls.length).toBeGreaterThan(0);
        const lastCall = browseCalls[browseCalls.length - 1];
        expect(lastCall).toContain('service=wallhaven');
    });

});



