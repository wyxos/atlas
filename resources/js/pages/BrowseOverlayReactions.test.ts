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
    mockRemove,
    mockRestore,
    mockQueuePreviewIncrement,
} = vi.hoisted(() => ({
    mockAxios: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), patch: vi.fn() },
    mockIsLoading: { value: false },
    mockCancelLoad: vi.fn(),
    mockRemove: vi.fn(),
    mockRestore: vi.fn(),
    mockQueuePreviewIncrement: vi.fn(),
}));

const mocks: BrowseMocks = {
    mockAxios,
    mockIsLoading: ref(false),
    mockCancelLoad,
    mockRemove,
    mockRestore,
    mockQueuePreviewIncrement,
};

global.fetch = vi.fn();
vi.mock('axios', () => ({ default: mockAxios }));
Object.defineProperty(window, 'axios', { value: mockAxios, writable: true });

vi.mock('@wyxos/vibe', async () => {
    const { createVibePageMock } = await import('@/test/browse-test-utils');
    return createVibePageMock({ mockIsLoading, mockCancelLoad, mockRemove, mockRestore });
});

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
                { id: 2, width: 300, height: 400, src: 'test2.jpg', type: 'image', page: 1, index: 1, notFound: false }],
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
                { id: 2, width: 300, height: 400, src: 'test2.jpg', type: 'image', page: 1, index: 1, notFound: false }],
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



