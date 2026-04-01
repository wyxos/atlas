import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { ref } from 'vue';
import Browse from './Browse.vue';
import FileViewer from '../components/FileViewer.vue';
import {
    setupBrowseTestMocks,
    createTestRouter,
    waitForStable,
    waitForTabContent,
    createMockTabConfig,
    setupAxiosMocks,
    type BrowseMocks,
} from '../test/browse-test-utils';

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
    const { createVibePageMock } = await import('../test/browse-test-utils');
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

describe('Browse - Middle Click Shortcuts', () => {
    it('opens original URL in new tab when middle clicking masonry item', async () => {
        const originalOpen = window.open;
        const mockOpen = vi.fn();
        window.open = mockOpen;

        const browseResponse = {
            items: [
                { id: 1, width: 300, height: 400, src: 'test1.jpg', preview: 'test1.jpg', original: 'https://example.com/original.jpg', type: 'image', page: 1, index: 0, notFound: false }],
            nextPage: null,
            services: [{ key: 'civit-ai-images', label: 'CivitAI Images' }],
        };
        const tabConfig = createMockTabConfig(1);
        setupAxiosMocks(mocks, tabConfig, browseResponse);
        const router = await createTestRouter();
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await waitForStable(wrapper);

        const tabContentVm = await waitForTabContent(wrapper);
        if (!tabContentVm) {
            window.open = originalOpen;
            return;
        }

        tabContentVm.items = [{ id: 1, width: 300, height: 400, src: 'test1.jpg', preview: 'test1.jpg', original: 'https://example.com/original.jpg', type: 'image', page: 1, index: 0, notFound: false }];
        await wrapper.vm.$nextTick();

        const browseTabContentComponent = wrapper.findComponent({ name: 'TabContent' });
        const masonryItem = browseTabContentComponent.find('[data-file-id="1"]');

        if (masonryItem.exists()) {
            const middleClickEvent = new MouseEvent('mousedown', {
                bubbles: true,
                button: 1,
            });
            Object.defineProperty(middleClickEvent, 'button', { value: 1, enumerable: true });
            masonryItem.element.dispatchEvent(middleClickEvent);

            const auxClickEvent = new MouseEvent('auxclick', {
                bubbles: true,
                button: 1,
            });
            Object.defineProperty(auxClickEvent, 'button', { value: 1, enumerable: true });
            masonryItem.element.dispatchEvent(auxClickEvent);

            await wrapper.vm.$nextTick();

            expect(mockOpen).toHaveBeenCalledWith('https://example.com/original.jpg', '_blank', 'noopener,noreferrer');
        }

        window.open = originalOpen;
    });

    it('opens original URL in new tab when middle clicking FileViewer overlay', async () => {
        const originalOpen = window.open;
        const mockOpen = vi.fn();
        window.open = mockOpen;

        const browseResponse = {
            items: [
                { id: 1, width: 300, height: 400, src: 'test1.jpg', preview: 'test1.jpg', original: 'https://example.com/original.jpg', type: 'image', page: 1, index: 0, notFound: false }],
            nextPage: null,
            services: [{ key: 'civit-ai-images', label: 'CivitAI Images' }],
        };
        const tabConfig = createMockTabConfig(1);
        setupAxiosMocks(mocks, tabConfig, browseResponse);
        const router = await createTestRouter();
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await waitForStable(wrapper);

        const tabContentVm = await waitForTabContent(wrapper);
        if (!tabContentVm) {
            window.open = originalOpen;
            return;
        }

        tabContentVm.items = [{ id: 1, width: 300, height: 400, src: 'test1.jpg', preview: 'test1.jpg', original: 'https://example.com/original.jpg', type: 'image', page: 1, index: 0, notFound: false }];
        await wrapper.vm.$nextTick();

        const browseTabContentComponent = wrapper.findComponent({ name: 'TabContent' });
        const fileViewer = browseTabContentComponent.findComponent(FileViewer);
        if (fileViewer.exists()) {
            const fileViewerVm = fileViewer.vm as any;
            fileViewerVm.currentItemIndex = 0;

            const middleClickEvent = new MouseEvent('mousedown', {
                bubbles: true,
                button: 1,
            });
            Object.defineProperty(middleClickEvent, 'button', { value: 1, enumerable: true });

            const auxClickEvent = new MouseEvent('auxclick', {
                bubbles: true,
                button: 1,
            });
            Object.defineProperty(auxClickEvent, 'button', { value: 1, enumerable: true });

            const overlayImage = fileViewer.find('img[src*="test1"]');
            if (overlayImage.exists()) {
                overlayImage.element.dispatchEvent(middleClickEvent);
                overlayImage.element.dispatchEvent(auxClickEvent);
                await wrapper.vm.$nextTick();

                expect(mockOpen).toHaveBeenCalledWith('https://example.com/original.jpg', '_blank', 'noopener,noreferrer');
            }
        }

        window.open = originalOpen;
    });
});



