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
});

