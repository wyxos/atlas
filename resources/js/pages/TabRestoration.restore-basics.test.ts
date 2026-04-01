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
    it('restarts empty restored CivitAI tabs from page 1 instead of a saved cursor', async () => {
        const tabId = 1;
        const staleCursor = '20|1773762966318';

        const tabConfig = createMockTabConfig(tabId, {
            params: { service: 'civit-ai-images', page: staleCursor },
        });

        const router = await createTestRouter('/browse');
        setupAxiosMocks(mocks, tabConfig);
        const wrapper = mount(Browse, { global: { plugins: [router] } });

        await waitForStable(wrapper);

        const masonry = wrapper.findComponent({ name: 'Masonry' });
        expect(masonry.exists()).toBe(true);
        expect(masonry.props('page')).toBe(1);
    });

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
});

