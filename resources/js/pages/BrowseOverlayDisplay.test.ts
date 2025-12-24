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
    setupBoundingClientRectMock,
    type BrowseMocks,
} from '../test/browse-test-utils';

// Define mocks using vi.hoisted
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

global.fetch = vi.fn();

vi.mock('axios', () => ({ default: mockAxios }));

Object.defineProperty(window, 'axios', { value: mockAxios, writable: true });

vi.mock('@wyxos/vibe', () => ({
    Masonry: {
        name: 'Masonry',
        template: '<div class="masonry-mock"><slot v-for="(item, index) in items" :key="item.id || index" :item="item" :remove="() => {}" :index="index"></slot></div>',
        props: ['items', 'getNextPage', 'layout', 'layoutMode', 'mobileBreakpoint', 'init', 'mode', 'backfillDelayMs', 'backfillMaxCalls'],
        emits: ['backfill:start', 'backfill:tick', 'backfill:stop', 'backfill:retry-start', 'backfill:retry-tick', 'backfill:retry-stop', 'update:items'],
        setup() {
            const exposed = { init: mockInit, refreshLayout: vi.fn(), cancelLoad: mockCancelLoad, destroy: mockDestroy, remove: mockRemove, removeMany: mockRemoveMany, restore: mockRestore, restoreMany: mockRestoreMany };
            Object.defineProperty(exposed, 'isLoading', { get: () => mockIsLoading.value, enumerable: true });
            return exposed;
        },
    },
    MasonryItem: {
        name: 'MasonryItem',
        template: '<div @mouseenter="$emit(\'mouseenter\', $event)" @mouseleave="$emit(\'mouseleave\', $event)"><slot :item="item" :remove="remove" :imageLoaded="true" :imageError="false" :videoLoaded="false" :videoError="false" :isLoading="false" :showMedia="true" :imageSrc="item?.src || item?.thumbnail || \'\'" :videoSrc="null"></slot></div>',
        props: ['item', 'remove'],
        emits: ['mouseenter', 'mouseleave', 'preload:success'],
    },
}));

vi.mock('@/composables/usePreviewBatch', () => ({
    usePreviewBatch: () => ({ queuePreviewIncrement: mockQueuePreviewIncrement }),
}));

beforeEach(() => {
    setupBrowseTestMocks(mocks);
    setupBoundingClientRectMock();
});

async function setupOverlayTest() {
    const tabConfig = createMockTabConfig(1);
    setupAxiosMocks(mocks, tabConfig);
    const router = await createTestRouter();
    const wrapper = mount(Browse, { global: { plugins: [router] } });
    await waitForStable(wrapper);
    return { wrapper, router };
}

describe('Browse - Overlay Display', () => {
    it('shows overlay when clicking on a masonry item', async () => {
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

        const browseTabContentComponent = wrapper.findComponent({ name: 'TabContent' });
        const masonryContainer = browseTabContentComponent.find('[ref="masonryContainer"]');
        if (masonryContainer.exists()) {
            const mockItem = document.createElement('div');
            mockItem.className = 'masonry-item';
            const mockImg = document.createElement('img');
            mockImg.src = 'test1.jpg';
            mockImg.setAttribute('srcset', 'test1.jpg 1x');
            mockImg.setAttribute('sizes', '(max-width: 300px) 300px');
            mockImg.setAttribute('alt', 'Test image');
            mockItem.appendChild(mockImg);
            masonryContainer.element.appendChild(mockItem);

            mockItem.getBoundingClientRect = vi.fn(() => ({
                top: 150, left: 250, width: 300, height: 400, bottom: 550, right: 550, x: 250, y: 150, toJSON: vi.fn(),
            }));

            const clickEvent = new MouseEvent('click', { bubbles: true });
            Object.defineProperty(clickEvent, 'target', { value: mockImg, enumerable: true });
            masonryContainer.element.dispatchEvent(clickEvent);

            await wrapper.vm.$nextTick();

            const fileViewer = wrapper.findComponent(FileViewer);
            const fileViewerVm = fileViewer.vm as any;
            expect(fileViewerVm.overlayRect).not.toBeNull();
            expect(fileViewerVm.overlayImage).not.toBeNull();
            expect(fileViewerVm.overlayImageSize).not.toBeNull();
        }
    });

    it('closes overlay when clicking close button', async () => {
        const { wrapper } = await setupOverlayTest();

        const fileViewer = wrapper.findComponent(FileViewer);
        const fileViewerVm = fileViewer.vm as any;

        fileViewerVm.overlayRect = { top: 100, left: 200, width: 300, height: 400 };
        fileViewerVm.overlayImage = { src: 'test.jpg', srcset: 'test.jpg 1x', sizes: '300px', alt: 'Test' };
        fileViewerVm.overlayImageSize = { width: 300, height: 400 };
        fileViewerVm.overlayIsFilled = true;
        fileViewerVm.overlayFillComplete = true;
        await wrapper.vm.$nextTick();

        const closeButton = wrapper.find('[data-test="close-overlay-button"]');
        expect(closeButton.exists()).toBe(true);

        await closeButton.trigger('click');
        await wrapper.vm.$nextTick();
        await waitForOverlayClose(fileViewerVm);

        expect(fileViewerVm.overlayRect).toBeNull();
        expect(fileViewerVm.overlayImage).toBeNull();
        expect(fileViewerVm.overlayImageSize).toBeNull();
        expect(fileViewerVm.overlayIsFilled).toBe(false);
    });

    it('closes overlay when clicking outside masonry item', async () => {
        const { wrapper } = await setupOverlayTest();

        const fileViewer = wrapper.findComponent(FileViewer);
        const fileViewerVm = fileViewer.vm as any;

        fileViewerVm.overlayRect = { top: 100, left: 200, width: 300, height: 400 };
        fileViewerVm.overlayImage = { src: 'test.jpg', srcset: 'test.jpg 1x', sizes: '300px', alt: 'Test' };
        fileViewerVm.overlayImageSize = { width: 300, height: 400 };
        await wrapper.vm.$nextTick();

        const masonryContainer = wrapper.find('[ref="masonryContainer"]');
        if (masonryContainer.exists()) {
            const clickEvent = new MouseEvent('click', { bubbles: true });
            Object.defineProperty(clickEvent, 'target', { value: masonryContainer.element, enumerable: true });
            masonryContainer.element.dispatchEvent(clickEvent);

            await wrapper.vm.$nextTick();

            expect(fileViewerVm.overlayRect).toBeNull();
            expect(fileViewerVm.overlayImage).toBeNull();
        }
    });

    it('maintains image size when overlay expands', async () => {
        const { wrapper } = await setupOverlayTest();

        const fileViewer = wrapper.findComponent(FileViewer);
        const fileViewerVm = fileViewer.vm as any;

        const originalWidth = 300;
        const originalHeight = 400;

        fileViewerVm.overlayRect = { top: 100, left: 200, width: originalWidth, height: originalHeight };
        fileViewerVm.overlayImage = { src: 'test.jpg', srcset: 'test.jpg 1x', sizes: '300px', alt: 'Test' };
        fileViewerVm.overlayImageSize = { width: originalWidth, height: originalHeight };
        fileViewerVm.overlayIsFilled = false;
        await wrapper.vm.$nextTick();

        expect(fileViewerVm.overlayImageSize.width).toBe(originalWidth);
        expect(fileViewerVm.overlayImageSize.height).toBe(originalHeight);

        fileViewerVm.overlayIsFilled = true;
        fileViewerVm.overlayRect = { top: 0, left: 0, width: 1920, height: 1080 };
        await wrapper.vm.$nextTick();

        expect(fileViewerVm.overlayImageSize.width).toBe(originalWidth);
        expect(fileViewerVm.overlayImageSize.height).toBe(originalHeight);
    });

    it('overlay has dark blue background', async () => {
        const { wrapper } = await setupOverlayTest();

        const fileViewer = wrapper.findComponent(FileViewer);
        const fileViewerVm = fileViewer.vm as any;

        fileViewerVm.overlayRect = { top: 100, left: 200, width: 300, height: 400 };
        fileViewerVm.overlayImage = { src: 'test.jpg', srcset: 'test.jpg 1x', sizes: '300px', alt: 'Test' };
        await wrapper.vm.$nextTick();

        const overlay = wrapper.find('.bg-prussian-blue-900');
        expect(overlay.exists()).toBe(true);
    });

    it('close button is only visible when overlay fill is complete and not closing', async () => {
        const { wrapper } = await setupOverlayTest();

        const fileViewer = wrapper.findComponent(FileViewer);
        const fileViewerVm = fileViewer.vm as any;

        fileViewerVm.overlayRect = { top: 100, left: 200, width: 300, height: 400 };
        fileViewerVm.overlayImage = { src: 'test.jpg', srcset: 'test.jpg 1x', sizes: '300px', alt: 'Test' };
        fileViewerVm.overlayIsFilled = false;
        fileViewerVm.overlayFillComplete = false;
        await wrapper.vm.$nextTick();

        let closeButton = wrapper.find('[data-test="close-overlay-button"]');
        expect(closeButton.exists()).toBe(false);

        fileViewerVm.overlayIsFilled = true;
        fileViewerVm.overlayFillComplete = false;
        await wrapper.vm.$nextTick();

        closeButton = wrapper.find('[data-test="close-overlay-button"]');
        expect(closeButton.exists()).toBe(false);

        fileViewerVm.overlayFillComplete = true;
        fileViewerVm.overlayIsClosing = false;
        await wrapper.vm.$nextTick();

        closeButton = wrapper.find('[data-test="close-overlay-button"]');
        expect(closeButton.exists()).toBe(true);

        fileViewerVm.overlayIsClosing = true;
        await wrapper.vm.$nextTick();

        closeButton = wrapper.find('[data-test="close-overlay-button"]');
        expect(closeButton.exists()).toBe(false);
    });

    it('animates overlay scale to 0 when closing', async () => {
        const { wrapper } = await setupOverlayTest();

        const fileViewer = wrapper.findComponent(FileViewer);
        const fileViewerVm = fileViewer.vm as any;

        fileViewerVm.overlayRect = { top: 100, left: 200, width: 300, height: 400 };
        fileViewerVm.overlayImage = { src: 'test.jpg', srcset: 'test.jpg 1x', sizes: '300px', alt: 'Test' };
        fileViewerVm.overlayIsFilled = true;
        fileViewerVm.overlayFillComplete = true;
        fileViewerVm.overlayScale = 1;
        await wrapper.vm.$nextTick();

        expect(fileViewerVm.overlayScale).toBe(1);
        const overlay = wrapper.find('.border-smart-blue-500');
        expect(overlay.exists()).toBe(true);
        const overlayStyle = overlay.attributes('style') || '';
        expect(overlayStyle).toContain('scale(1)');

        fileViewerVm.closeOverlay();
        await wrapper.vm.$nextTick();

        expect(fileViewerVm.overlayScale).toBe(0);
        expect(fileViewerVm.overlayIsClosing).toBe(true);
        await wrapper.vm.$nextTick();

        const updatedOverlay = wrapper.find('.border-smart-blue-500');
        const updatedStyle = updatedOverlay.attributes('style') || '';
        expect(updatedStyle).toContain('scale(0)');
    });

    it('has overflow hidden during closing animation', async () => {
        const { wrapper } = await setupOverlayTest();

        const fileViewer = wrapper.findComponent(FileViewer);
        const fileViewerVm = fileViewer.vm as any;

        fileViewerVm.overlayRect = { top: 0, left: 0, width: 1920, height: 1080 };
        fileViewerVm.overlayImage = { src: 'test.jpg', srcset: 'test.jpg 1x', sizes: '300px', alt: 'Test' };
        fileViewerVm.overlayIsFilled = true;
        fileViewerVm.overlayIsClosing = false;
        await wrapper.vm.$nextTick();

        let overlay = wrapper.find('.border-smart-blue-500');
        expect(overlay.exists()).toBe(true);
        expect(overlay.classes()).toContain('overflow-hidden');

        fileViewerVm.overlayIsClosing = true;
        await wrapper.vm.$nextTick();

        overlay = wrapper.find('.border-smart-blue-500');
        expect(overlay.exists()).toBe(true);
        expect(overlay.classes()).toContain('overflow-hidden');
    });

    it('has correct border styling', async () => {
        const { wrapper } = await setupOverlayTest();

        const fileViewer = wrapper.findComponent(FileViewer);
        const fileViewerVm = fileViewer.vm as any;

        fileViewerVm.overlayRect = { top: 0, left: 0, width: 1920, height: 1080 };
        fileViewerVm.overlayImage = { src: 'test.jpg', srcset: 'test.jpg 1x', sizes: '300px', alt: 'Test' };
        fileViewerVm.overlayIsFilled = true;
        await wrapper.vm.$nextTick();

        const overlay = wrapper.find('.border-smart-blue-500');
        expect(overlay.exists()).toBe(true);
        expect(overlay.classes()).toContain('border-4');
        expect(overlay.classes()).toContain('border-smart-blue-500');
    });
});
