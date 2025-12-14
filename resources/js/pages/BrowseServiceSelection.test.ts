import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { ref } from 'vue';
import Browse from './Browse.vue';
import {
    setupBrowseTestMocks,
    createTestRouter,
    getBrowseTabContent,
    waitForStable,
    type BrowseMocks,
} from '../test/browse-test-utils';

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
        props: ['items', 'getNextPage', 'loadAtPage', 'layout', 'layoutMode', 'mobileBreakpoint', 'skipInitialLoad', 'backfillEnabled', 'backfillDelayMs', 'backfillMaxCalls'],
        emits: ['backfill:start', 'backfill:tick', 'backfill:stop', 'backfill:retry-start', 'backfill:retry-tick', 'backfill:retry-stop', 'update:items'],
        setup() {
            return {
                isLoading: mockIsLoading,
                init: mockInit,
                refreshLayout: vi.fn(),
                cancelLoad: mockCancelLoad,
                destroy: mockDestroy,
                remove: mockRemove,
                removeMany: mockRemoveMany,
                restore: mockRestore,
                restoreMany: mockRestoreMany,
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
            if (url.includes('/api/browse-tabs')) {
                return Promise.resolve({ data: [] });
            }
            if (url.includes('/api/browse')) {
                return Promise.resolve({
                    data: {
                        items: [],
                        nextPage: null,
                        services: [
                            { key: 'civit-ai-images', label: 'CivitAI Images' },
                            { key: 'wallhaven', label: 'Wallhaven' },
                        ],
                    },
                });
            }
            return Promise.resolve({ data: { items: [], nextPage: null } });
        });

        mocks.mockAxios.post.mockResolvedValue({
            data: {
                id: 1,
                label: 'Browse 1',
                query_params: {},
                file_ids: [],
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

        const tabContentVm = getBrowseTabContent(wrapper);
        if (tabContentVm) {
            expect(tabContentVm.hasServiceSelected).toBe(false);
            expect(tabContentVm.loadAtPage).toBe(null);
            expect(tabContentVm.items.length).toBe(0);
        } else {
            const itemLoadingCalls = mocks.mockAxios.get.mock.calls
                .map(call => call[0])
                .filter((callUrl: string) => {
                    return callUrl.includes('/api/browse') && callUrl.includes('source=');
                });
            expect(itemLoadingCalls.length).toBe(0);
        }

        const itemLoadingCalls = mocks.mockAxios.get.mock.calls
            .map(call => call[0])
            .filter((callUrl: string) => {
                return callUrl.includes('/api/browse') && callUrl.includes('source=');
            });
        expect(itemLoadingCalls.length).toBe(0);
    });

    it('applies selected service and triggers loading', async () => {
        mocks.mockAxios.get.mockImplementation((url: string) => {
            if (url.includes('/api/browse-tabs')) {
                return Promise.resolve({
                    data: [{
                        id: 1,
                        label: 'Test Tab',
                        query_params: {},
                        file_ids: [],
                        position: 0,
                    }],
                });
            }
            if (url.includes('/api/browse')) {
                return Promise.resolve({
                    data: {
                        items: [
                            { id: 1, width: 100, height: 100, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false },
                        ],
                        nextPage: 'cursor-2',
                        services: [
                            { key: 'civit-ai-images', label: 'CivitAI Images' },
                            { key: 'wallhaven', label: 'Wallhaven' },
                        ],
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

        const tabContentVm = getBrowseTabContent(wrapper);
        if (!tabContentVm) {
            return;
        }

        expect(tabContentVm.hasServiceSelected).toBe(false);

        tabContentVm.selectedService = 'civit-ai-images';
        await wrapper.vm.$nextTick();

        await tabContentVm.applyService();
        await waitForStable(wrapper);

        const activeTab = vm.getActiveTab();
        expect(activeTab.queryParams.service).toBe('civit-ai-images');
        expect(tabContentVm.loadAtPage).toBe(1);
        expect(tabContentVm.hasServiceSelected).toBe(true);

        const masonry = wrapper.findComponent({ name: 'Masonry' });
        expect(masonry.exists()).toBe(true);

        if (tabContentVm.masonry && tabContentVm.loadAtPage !== null && tabContentVm.items.length === 0) {
            await tabContentVm.getNextPage(tabContentVm.loadAtPage);
            await flushPromises();
            await wrapper.vm.$nextTick();
        }

        const browseCalls = mocks.mockAxios.get.mock.calls
            .map(call => call[0])
            .filter((callUrl: string) => {
                return typeof callUrl === 'string'
                    && callUrl.includes('/api/browse?')
                    && !callUrl.includes('/api/browse-tabs')
                    && !callUrl.includes('limit=1');
            });
        expect(browseCalls.length).toBeGreaterThan(0);
        const lastCall = browseCalls[browseCalls.length - 1];
        expect(lastCall).toContain('source=civit-ai-images');
    });

    it('restores service when switching to tab with saved service', async () => {
        const tab1Id = 1;
        const tab2Id = 2;

        mocks.mockAxios.get.mockImplementation((url: string) => {
            if (url.includes('/api/browse-tabs')) {
                return Promise.resolve({
                    data: [
                        {
                            id: tab1Id,
                            label: 'Tab 1',
                            query_params: { service: 'civit-ai-images', page: 1 },
                            file_ids: [],
                            position: 0,
                        },
                        {
                            id: tab2Id,
                            label: 'Tab 2',
                            query_params: { service: 'wallhaven', page: 1 },
                            file_ids: [],
                            position: 1,
                        },
                    ],
                });
            }
            if (url.includes('/api/browse')) {
                return Promise.resolve({
                    data: {
                        items: [],
                        nextPage: null,
                        services: [
                            { key: 'civit-ai-images', label: 'CivitAI Images' },
                            { key: 'wallhaven', label: 'Wallhaven' },
                        ],
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

        let tabContentVm = getBrowseTabContent(wrapper);
        if (tabContentVm) {
            expect(tabContentVm.currentTabService).toBe('civit-ai-images');
            expect(tabContentVm.selectedService).toBe('civit-ai-images');
        }

        await vm.switchTab(tab2Id);
        await waitForStable(wrapper);

        expect(vm.activeTabId).toBe(tab2Id);
        tabContentVm = getBrowseTabContent(wrapper);
        if (tabContentVm) {
            expect(tabContentVm.currentTabService).toBe('wallhaven');
            expect(tabContentVm.selectedService).toBe('wallhaven');
        }
    });

    it('includes service parameter in browse API calls', async () => {
        const tabId = 1;

        mocks.mockAxios.get.mockImplementation((url: string) => {
            if (url.includes('/api/browse-tabs')) {
                return Promise.resolve({
                    data: [{
                        id: tabId,
                        label: 'Test Tab',
                        query_params: { service: 'wallhaven', page: 1 },
                        file_ids: [],
                        position: 0,
                    }],
                });
            }
            if (url.includes('/api/browse')) {
                return Promise.resolve({
                    data: {
                        items: [
                            { id: 1, width: 100, height: 100, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false },
                        ],
                        nextPage: 'cursor-2',
                        services: [
                            { key: 'civit-ai-images', label: 'CivitAI Images' },
                            { key: 'wallhaven', label: 'Wallhaven' },
                        ],
                    },
                });
            }
            return Promise.resolve({ data: { items: [], nextPage: null } });
        });

        const router = await createTestRouter('/browse');
        const wrapper = mount(Browse, { global: { plugins: [router] } });

        await waitForStable(wrapper);

        const tabContentVm = getBrowseTabContent(wrapper);
        if (!tabContentVm) {
            return;
        }

        tabContentVm.isTabRestored = false;
        tabContentVm.loadAtPage = 1;

        await tabContentVm.getNextPage(1);
        await flushPromises();

        const browseCalls = mocks.mockAxios.get.mock.calls
            .map(call => call[0])
            .filter((callUrl: string) => callUrl.includes('/api/browse') && !callUrl.includes('services'));

        expect(browseCalls.length).toBeGreaterThan(0);
        const lastCall = browseCalls[browseCalls.length - 1];
        expect(lastCall).toContain('source=wallhaven');
    });

    it('registers backfill event handlers on masonry component', async () => {
        mocks.mockAxios.get.mockImplementation((url: string) => {
            if (url.includes('/api/browse-tabs')) {
                return Promise.resolve({
                    data: [{
                        id: 1,
                        label: 'Test Tab',
                        query_params: { service: 'civit-ai-images', page: 1 },
                        file_ids: [],
                        position: 0,
                    }],
                });
            }
            if (url.includes('/api/browse')) {
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

        const router = await createTestRouter('/browse');
        const wrapper = mount(Browse, { global: { plugins: [router] } });

        await waitForStable(wrapper);
        await waitForStable(wrapper);

        const tabContentVm = getBrowseTabContent(wrapper);
        if (!tabContentVm) {
            return;
        }

        expect(typeof tabContentVm.onBackfillStart).toBe('function');
        expect(typeof tabContentVm.onBackfillTick).toBe('function');
        expect(typeof tabContentVm.onBackfillStop).toBe('function');
        expect(typeof tabContentVm.onBackfillRetryStart).toBe('function');
        expect(typeof tabContentVm.onBackfillRetryTick).toBe('function');
        expect(typeof tabContentVm.onBackfillRetryStop).toBe('function');

        expect(tabContentVm.backfill).toBeDefined();
        expect(tabContentVm.backfill.active).toBe(false);
        expect(tabContentVm.backfill.fetched).toBe(0);
        expect(tabContentVm.backfill.target).toBe(0);
    });
});
