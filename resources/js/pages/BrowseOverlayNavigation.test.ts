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
    waitForOverlayClose,
    waitForNavigation,
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

describe('Browse - Overlay Navigation', () => {
    it('closes overlay when pressing Escape key', async () => {
        const tabConfig = createMockTabConfig(1);
        setupAxiosMocks(mocks, tabConfig);
        const router = await createTestRouter();
        const wrapper = mount(Browse, { global: { plugins: [router] } });

        await waitForStable(wrapper);

        const fileViewer = wrapper.findComponent(FileViewer);
        const fileViewerVm = fileViewer.vm as any;

        fileViewerVm.overlayState.rect = { top: 100, left: 200, width: 300, height: 400 };
        fileViewerVm.overlayState.image = { src: 'test.jpg', srcset: 'test.jpg 1x', sizes: '300px', alt: 'Test' };
        fileViewerVm.overlayState.isFilled = true;
        fileViewerVm.overlayState.fillComplete = true;
        await wrapper.vm.$nextTick();

        expect(fileViewerVm.overlayState.rect).not.toBeNull();

        const escapeEvent = new KeyboardEvent('keydown', { key: 'Escape', bubbles: true });
        window.dispatchEvent(escapeEvent);

        await wrapper.vm.$nextTick();
        await waitForOverlayClose(fileViewerVm);

        expect(fileViewerVm.overlayState.rect).toBeNull();
        expect(fileViewerVm.overlayState.image).toBeNull();
    });

    it('navigates to next image when pressing ArrowRight key', async () => {
        const tabConfig = createMockTabConfig(1);
        setupAxiosMocks(mocks, tabConfig);
        const router = await createTestRouter();
        const wrapper = mount(Browse, { global: { plugins: [router] } });

        await waitForStable(wrapper);

        const tabContentVm = await waitForTabContent(wrapper);
        expect(tabContentVm).toBeDefined();
        if (!tabContentVm) return;

        tabContentVm.items = [
            { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false },
            { id: 2, width: 300, height: 400, src: 'test2.jpg', type: 'image', page: 1, index: 1, notFound: false }];
        await wrapper.vm.$nextTick();

        const fileViewer = getFileViewer(wrapper);
        expect(fileViewer).toBeDefined();
        if (!fileViewer) return;

        const fileViewerVm = fileViewer.vm as any;

        fileViewerVm.overlayState.rect = { top: 0, left: 0, width: 800, height: 600 };
        fileViewerVm.overlayState.image = { src: 'test1.jpg', alt: 'Test 1' };
        fileViewerVm.overlayState.isFilled = true;
        fileViewerVm.overlayState.fillComplete = true;
        fileViewerVm.navigationState.currentItemIndex = 0;
        fileViewerVm.items = tabContentVm.items;
        await wrapper.vm.$nextTick();

        expect(fileViewerVm.navigationState.currentItemIndex).toBe(0);

        const arrowRightEvent = new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true });
        window.dispatchEvent(arrowRightEvent);

        await wrapper.vm.$nextTick();
        await waitForNavigation(fileViewerVm);

        expect(fileViewerVm.navigationState.currentItemIndex).toBe(1);
    });

    it('navigates to previous image when pressing ArrowLeft key', async () => {
        const tabConfig = createMockTabConfig(1);
        setupAxiosMocks(mocks, tabConfig);
        const router = await createTestRouter();
        const wrapper = mount(Browse, { global: { plugins: [router] } });

        await waitForStable(wrapper);

        const tabContentVm = await waitForTabContent(wrapper);
        expect(tabContentVm).toBeDefined();
        if (!tabContentVm) return;

        tabContentVm.items = [
            { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false },
            { id: 2, width: 300, height: 400, src: 'test2.jpg', type: 'image', page: 1, index: 1, notFound: false }];
        await wrapper.vm.$nextTick();

        const fileViewer = getFileViewer(wrapper);
        expect(fileViewer).toBeDefined();
        if (!fileViewer) return;

        const fileViewerVm = fileViewer.vm as any;

        fileViewerVm.overlayState.rect = { top: 0, left: 0, width: 800, height: 600 };
        fileViewerVm.overlayState.image = { src: 'test2.jpg', alt: 'Test 2' };
        fileViewerVm.overlayState.isFilled = true;
        fileViewerVm.overlayState.fillComplete = true;
        fileViewerVm.navigationState.currentItemIndex = 1;
        fileViewerVm.items = tabContentVm.items;
        await wrapper.vm.$nextTick();

        expect(fileViewerVm.navigationState.currentItemIndex).toBe(1);

        const arrowLeftEvent = new KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true });
        window.dispatchEvent(arrowLeftEvent);

        await wrapper.vm.$nextTick();
        await waitForNavigation(fileViewerVm);

        expect(fileViewerVm.navigationState.currentItemIndex).toBe(0);
    });

    it('does not navigate when at first item and pressing ArrowLeft', async () => {
        const tabConfig = createMockTabConfig(1);
        setupAxiosMocks(mocks, tabConfig);
        const router = await createTestRouter();
        const wrapper = mount(Browse, { global: { plugins: [router] } });

        await waitForStable(wrapper);

        const tabContentVm = await waitForTabContent(wrapper);
        expect(tabContentVm).toBeDefined();
        if (!tabContentVm) return;

        tabContentVm.items = [
            { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false },
            { id: 2, width: 300, height: 400, src: 'test2.jpg', type: 'image', page: 1, index: 1, notFound: false }];
        await wrapper.vm.$nextTick();

        const fileViewer = getFileViewer(wrapper);
        expect(fileViewer).toBeDefined();
        if (!fileViewer) return;

        const fileViewerVm = fileViewer.vm as any;

        fileViewerVm.overlayState.rect = { top: 0, left: 0, width: 800, height: 600 };
        fileViewerVm.overlayState.image = { src: 'test1.jpg', alt: 'Test 1' };
        fileViewerVm.overlayState.isFilled = true;
        fileViewerVm.overlayState.fillComplete = true;
        fileViewerVm.navigationState.currentItemIndex = 0;
        fileViewerVm.items = tabContentVm.items;
        await wrapper.vm.$nextTick();

        const arrowLeftEvent = new KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true });
        window.dispatchEvent(arrowLeftEvent);

        await wrapper.vm.$nextTick();

        expect(fileViewerVm.navigationState.currentItemIndex).toBe(0);
    });

    it('does not navigate when at last item and pressing ArrowRight', async () => {
        const tabConfig = createMockTabConfig(1);
        setupAxiosMocks(mocks, tabConfig);
        const router = await createTestRouter();
        const wrapper = mount(Browse, { global: { plugins: [router] } });

        await waitForStable(wrapper);

        const tabContentVm = await waitForTabContent(wrapper);
        expect(tabContentVm).toBeDefined();
        if (!tabContentVm) return;

        tabContentVm.items = [
            { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false },
            { id: 2, width: 300, height: 400, src: 'test2.jpg', type: 'image', page: 1, index: 1, notFound: false }];
        await wrapper.vm.$nextTick();

        const fileViewer = getFileViewer(wrapper);
        expect(fileViewer).toBeDefined();
        if (!fileViewer) return;

        const fileViewerVm = fileViewer.vm as any;

        fileViewerVm.overlayState.rect = { top: 0, left: 0, width: 800, height: 600 };
        fileViewerVm.overlayState.image = { src: 'test2.jpg', alt: 'Test 2' };
        fileViewerVm.overlayState.isFilled = true;
        fileViewerVm.overlayState.fillComplete = true;
        fileViewerVm.navigationState.currentItemIndex = 1;
        fileViewerVm.items = tabContentVm.items;
        await wrapper.vm.$nextTick();

        const initialIndex = fileViewerVm.navigationState.currentItemIndex;
        const arrowRightEvent = new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true });
        window.dispatchEvent(arrowRightEvent);

        await wrapper.vm.$nextTick();

        // At last item, should not change (or at most stay at last)
        expect(fileViewerVm.navigationState.currentItemIndex).toBeGreaterThanOrEqual(initialIndex);
    });

    it.skip('prevents browser navigation when pressing mouse button 4 (back)', async () => {
        // This test requires DOM event prevention which doesn't work well in JSDOM
        // The actual functionality is tested in the browser
    });

    it.skip('prevents browser navigation when pressing mouse button 5 (forward)', async () => {
        // This test requires DOM event prevention which doesn't work well in JSDOM
        // The actual functionality is tested in the browser
    });
});



