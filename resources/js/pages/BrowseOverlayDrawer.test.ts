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
    setupBoundingClientRectMock,
    type BrowseMocks,
} from '../test/browse-test-utils';

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
    const { createVibePageMock } = await import('../test/browse-test-utils');
    return createVibePageMock({ mockIsLoading, mockCancelLoad, mockRemove, mockRestore });
});

vi.mock('@/composables/usePreviewBatch', () => ({
    usePreviewBatch: () => ({ queuePreviewIncrement: mockQueuePreviewIncrement }),
}));

beforeEach(() => {
    setupBrowseTestMocks(mocks);
    setupBoundingClientRectMock();
});

function getFileViewer(wrapper: any) {
    const browseTabContent = wrapper.findComponent({ name: 'TabContent' });
    if (browseTabContent.exists()) {
        const fileViewer = browseTabContent.findComponent(FileViewer);
        if (fileViewer.exists()) return fileViewer;
    }
    return null;
}

describe('Browse - Overlay Drawer', () => {
    it('opens sheet when clicking the taskbar button', async () => {
        const browseResponse = {
            items: [{ id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false }],
            nextPage: null,
            services: [{ key: 'civit-ai-images', label: 'CivitAI Images' }],
        };
        const tabConfig = createMockTabConfig(1);
        setupAxiosMocks(mocks, tabConfig, browseResponse);
        const router = await createTestRouter();
        const wrapper = mount(Browse, { global: { plugins: [router] } });

        await waitForStable(wrapper);

        const tabContentVm = await waitForTabContent(wrapper);
        expect(tabContentVm).toBeDefined();
        if (!tabContentVm) return;

        tabContentVm.items = [{ id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false }];
        await wrapper.vm.$nextTick();

        const fileViewer = getFileViewer(wrapper);
        expect(fileViewer).toBeDefined();
        if (!fileViewer) return;

        const fileViewerVm = fileViewer.vm as any;

        fileViewerVm.overlayState.rect = { top: 0, left: 0, width: 800, height: 600 };
        fileViewerVm.overlayState.image = { src: 'test1.jpg', alt: 'Test 1' };
        fileViewerVm.overlayState.isFilled = true;
        fileViewerVm.overlayState.fillComplete = true;
        fileViewerVm.currentItemIndex = 0;
        fileViewerVm.overlayState.fullSizeImage = 'test1-full.jpg';
        fileViewerVm.overlayState.isLoading = false;
        await wrapper.vm.$nextTick();

        const openSheetButton = fileViewer.find('button[aria-label="Open sheet"]');
        expect(openSheetButton.exists()).toBe(true);

        await openSheetButton.trigger('click');
        await wrapper.vm.$nextTick();

        expect(fileViewerVm.sheetState.isOpen).toBe(true);
    });

    it('closes sheet when clicking the close button', async () => {
        const browseResponse = {
            items: [{ id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false }],
            nextPage: null,
            services: [{ key: 'civit-ai-images', label: 'CivitAI Images' }],
        };
        const tabConfig = createMockTabConfig(1);
        setupAxiosMocks(mocks, tabConfig, browseResponse);
        const router = await createTestRouter();
        const wrapper = mount(Browse, { global: { plugins: [router] } });

        await waitForStable(wrapper);

        const tabContentVm = await waitForTabContent(wrapper);
        expect(tabContentVm).toBeDefined();
        if (!tabContentVm) return;

        tabContentVm.items = [{ id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false }];
        await wrapper.vm.$nextTick();

        const fileViewer = getFileViewer(wrapper);
        expect(fileViewer).toBeDefined();
        if (!fileViewer) return;

        const fileViewerVm = fileViewer.vm as any;

        fileViewerVm.overlayState.rect = { top: 0, left: 0, width: 800, height: 600 };
        fileViewerVm.overlayState.image = { src: 'test1.jpg', alt: 'Test 1' };
        fileViewerVm.overlayState.isFilled = true;
        fileViewerVm.overlayState.fillComplete = true;
        fileViewerVm.currentItemIndex = 0;
        fileViewerVm.overlayState.fullSizeImage = 'test1-full.jpg';
        fileViewerVm.overlayState.isLoading = false;
        fileViewerVm.sheetState.isOpen = true;
        await wrapper.vm.$nextTick();

        const closeButton = fileViewer.find('button[aria-label="Hide details panel"]');
        expect(closeButton.exists()).toBe(true);

        await closeButton.trigger('click');
        await wrapper.vm.$nextTick();

        expect(fileViewerVm.sheetState.isOpen).toBe(false);
    });
});

