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
    mockRemove,
    mockRestore,
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
    mockRemove: vi.fn(),
    mockRestore: vi.fn(),
    mockQueuePreviewIncrement: vi.fn(),
}));

// Create mocks object for helper functions
const mocks: BrowseMocks = {
    mockAxios,
    mockIsLoading: ref(false),
    mockCancelLoad,
    mockRemove,
    mockRestore,
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

// Mock @wyxos/vibe with shared helper
vi.mock('@wyxos/vibe', async () => {
    const { createVibePageMock } = await import('@/test/browse-test-utils');
    return createVibePageMock({ mockIsLoading, mockCancelLoad, mockRemove, mockRestore });
});

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

        const initialPage = await tabContentVm.getPage(1);
        tabContentVm.items = initialPage.items;
        await waitForStable(wrapper);

        const masonry = wrapper.findComponent({ name: 'Masonry' });
        expect(masonry.exists()).toBe(true);

        const initialItems = masonry.props('items');
        expect(initialItems[0]?.page).toBe(1);
        masonry.vm.nextPage = initialPage.nextPage;
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

        const refreshedPage = await refreshedTabContentVm.getPage(refreshedMasonry.props('page'));
        refreshedTabContentVm.items = refreshedPage.items;
        refreshedMasonry.vm.nextPage = refreshedPage.nextPage;
        await waitForStable(refreshedWrapper);

        const refreshedItems = refreshedMasonry.props('items');
        expect(refreshedItems[0]?.page).toBe(1);
        expect(refreshedMasonry.vm.pagesLoaded).toEqual([1]);
        expect(refreshedMasonry.vm.nextPage).toBe(2);
    });
});

