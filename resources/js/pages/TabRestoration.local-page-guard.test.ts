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
});

