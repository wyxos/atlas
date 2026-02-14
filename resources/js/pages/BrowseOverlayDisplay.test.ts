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
        props: ['items', 'getContent', 'getPage', 'page', 'layout', 'layoutMode', 'init', 'mode', 'restoredPages', 'pageSize', 'gapX', 'gapY'],
        emits: ['update:items', 'preloaded', 'failures'],
        setup(props: { items: any[]; getPage?: (page: number | string) => Promise<{ items?: any[]; nextPage?: number | string | null }> }, { emit }: { emit: (event: string, value: any) => void }) {
            let currentPage: number | string | null = null;
            let nextPage: number | string | null = null;
            let hasReachedEnd = false;
            let paginationHistory: Array<number | string> = [];

            const initialize = (itemsToRestore: any[], page: number | string, next: number | string | null) => {
                mockInit(itemsToRestore, page, next);
                props.items.splice(0, props.items.length, ...itemsToRestore);
                emit('update:items', props.items);
                currentPage = page;
                nextPage = next ?? null;
                paginationHistory = nextPage === null ? [] : [nextPage];
                hasReachedEnd = nextPage === null;
            };

            const loadPage = async (page: number | string) => {
                if (!props.getPage) {
                    return;
                }
                currentPage = page;
                const result = await props.getPage(page);
                const newItems = result?.items ?? [];
                props.items.splice(0, props.items.length, ...newItems);
                emit('update:items', props.items);
                nextPage = result?.nextPage ?? null;
                paginationHistory = nextPage === null ? [] : [nextPage];
                hasReachedEnd = nextPage === null;
                return result;
            };

            const loadNext = async () => {
                if (!props.getPage || nextPage === null || nextPage === undefined) {
                    return;
                }
                const pageToLoad = nextPage;
                currentPage = pageToLoad;
                const result = await props.getPage(pageToLoad);
                const newItems = result?.items ?? [];
                props.items.push(...newItems);
                emit('update:items', props.items);
                nextPage = result?.nextPage ?? null;
                paginationHistory = nextPage === null ? [] : [nextPage];
                hasReachedEnd = nextPage === null;
                return result;
            };

            const reset = () => {
                props.items.splice(0, props.items.length);
                emit('update:items', props.items);
                currentPage = null;
                nextPage = null;
                paginationHistory = [];
                hasReachedEnd = false;
            };

            const exposed = {
                init: mockInit,
                initialize,
                cancelLoad: mockCancelLoad,
                destroy: mockDestroy,
                remove: mockRemove,
                removeMany: mockRemoveMany,
                restore: mockRestore,
                restoreMany: mockRestoreMany,
                loadPage,
                loadNext,
                reset,
            };
            Object.defineProperty(exposed, 'isLoading', { get: () => mockIsLoading.value, enumerable: true });
            Object.defineProperty(exposed, 'hasReachedEnd', { get: () => hasReachedEnd, enumerable: true });
            Object.defineProperty(exposed, 'currentPage', { get: () => currentPage, enumerable: true });
            Object.defineProperty(exposed, 'nextPage', { get: () => nextPage, enumerable: true });
            Object.defineProperty(exposed, 'paginationHistory', { get: () => paginationHistory, enumerable: true });
            return exposed;
        },
    },
    MasonryItem: {
        name: 'MasonryItem',
        template: '<div @mouseenter="$emit(\'mouseenter\', $event)" @mouseleave="$emit(\'mouseleave\', $event)"><slot :item="item" :remove="remove" :imageLoaded="true" :imageError="false" :videoLoaded="false" :videoError="false" :isLoading="false" :showMedia="true" :imageSrc="item?.preview" :videoSrc="null"></slot></div>',
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
            const mockItem = document.createElement('article');
            mockItem.setAttribute('data-testid', 'item-card');
            const mockImg = document.createElement('img');
            mockImg.src = 'test1.jpg';
            mockImg.setAttribute('srcset', 'test1.jpg 1x');
            mockImg.setAttribute('sizes', '(max-width: 300px) 300px');
            mockImg.setAttribute('alt', 'Test image');
            mockItem.appendChild(mockImg);

            const mockOverlay = document.createElement('div');
            mockOverlay.setAttribute('data-file-id', '1');
            mockItem.appendChild(mockOverlay);
            masonryContainer.element.appendChild(mockItem);

            mockItem.getBoundingClientRect = vi.fn(() => ({
                top: 150, left: 250, width: 300, height: 400, bottom: 550, right: 550, x: 250, y: 150, toJSON: vi.fn(),
            }));

            const clickEvent = new MouseEvent('click', { bubbles: true });
            Object.defineProperty(clickEvent, 'target', { value: mockOverlay, enumerable: true });
            masonryContainer.element.dispatchEvent(clickEvent);

            await wrapper.vm.$nextTick();

            const fileViewer = wrapper.findComponent(FileViewer);
            const fileViewerVm = fileViewer.vm as any;
            expect(fileViewerVm.overlayState.rect).not.toBeNull();
            expect(fileViewerVm.overlayState.image).not.toBeNull();
            expect(fileViewerVm.overlayState.imageSize).not.toBeNull();
        }
    });

    it('closes overlay when clicking close button', async () => {
        const { wrapper } = await setupOverlayTest();

        const fileViewer = wrapper.findComponent(FileViewer);
        const fileViewerVm = fileViewer.vm as any;

        fileViewerVm.overlayState.rect = { top: 100, left: 200, width: 300, height: 400 };
        fileViewerVm.overlayState.image = { src: 'test.jpg', srcset: 'test.jpg 1x', sizes: '300px', alt: 'Test' };
        fileViewerVm.overlayState.imageSize = { width: 300, height: 400 };
        fileViewerVm.overlayState.isFilled = true;
        fileViewerVm.overlayState.fillComplete = true;
        await wrapper.vm.$nextTick();

        const closeButton = wrapper.find('[data-test="close-overlay-button"]');
        expect(closeButton.exists()).toBe(true);

        await closeButton.trigger('click');
        await wrapper.vm.$nextTick();
        await waitForOverlayClose(fileViewerVm);

        expect(fileViewerVm.overlayState.rect).toBeNull();
        expect(fileViewerVm.overlayState.image).toBeNull();
        expect(fileViewerVm.overlayState.imageSize).toBeNull();
        expect(fileViewerVm.overlayState.isFilled).toBe(false);
    });

    it('closes overlay when clicking outside masonry item', async () => {
        const { wrapper } = await setupOverlayTest();

        const fileViewer = wrapper.findComponent(FileViewer);
        const fileViewerVm = fileViewer.vm as any;

        fileViewerVm.overlayState.rect = { top: 100, left: 200, width: 300, height: 400 };
        fileViewerVm.overlayState.image = { src: 'test.jpg', srcset: 'test.jpg 1x', sizes: '300px', alt: 'Test' };
        fileViewerVm.overlayState.imageSize = { width: 300, height: 400 };
        await wrapper.vm.$nextTick();

        const masonryContainer = wrapper.find('[ref="masonryContainer"]');
        if (masonryContainer.exists()) {
            const clickEvent = new MouseEvent('click', { bubbles: true });
            Object.defineProperty(clickEvent, 'target', { value: masonryContainer.element, enumerable: true });
            masonryContainer.element.dispatchEvent(clickEvent);

            await wrapper.vm.$nextTick();

            expect(fileViewerVm.overlayState.rect).toBeNull();
            expect(fileViewerVm.overlayState.image).toBeNull();
        }
    });

    it('maintains image size when overlay expands', async () => {
        const { wrapper } = await setupOverlayTest();

        const fileViewer = wrapper.findComponent(FileViewer);
        const fileViewerVm = fileViewer.vm as any;

        const originalWidth = 300;
        const originalHeight = 400;

        fileViewerVm.overlayState.rect = { top: 100, left: 200, width: originalWidth, height: originalHeight };
        fileViewerVm.overlayState.image = { src: 'test.jpg', srcset: 'test.jpg 1x', sizes: '300px', alt: 'Test' };
        fileViewerVm.overlayState.imageSize = { width: originalWidth, height: originalHeight };
        fileViewerVm.overlayState.isFilled = false;
        await wrapper.vm.$nextTick();

        expect(fileViewerVm.overlayState.imageSize.width).toBe(originalWidth);
        expect(fileViewerVm.overlayState.imageSize.height).toBe(originalHeight);

        fileViewerVm.overlayState.isFilled = true;
        fileViewerVm.overlayState.rect = { top: 0, left: 0, width: 1920, height: 1080 };
        await wrapper.vm.$nextTick();

        expect(fileViewerVm.overlayState.imageSize.width).toBe(originalWidth);
        expect(fileViewerVm.overlayState.imageSize.height).toBe(originalHeight);
    });

    it('overlay has dark blue background', async () => {
        const { wrapper } = await setupOverlayTest();

        const fileViewer = wrapper.findComponent(FileViewer);
        const fileViewerVm = fileViewer.vm as any;

        fileViewerVm.overlayState.rect = { top: 100, left: 200, width: 300, height: 400 };
        fileViewerVm.overlayState.image = { src: 'test.jpg', srcset: 'test.jpg 1x', sizes: '300px', alt: 'Test' };
        await wrapper.vm.$nextTick();

        const overlay = wrapper.find('.bg-prussian-blue-900');
        expect(overlay.exists()).toBe(true);
    });

    it('close button is only visible when overlay fill is complete and not closing', async () => {
        const { wrapper } = await setupOverlayTest();

        const fileViewer = wrapper.findComponent(FileViewer);
        const fileViewerVm = fileViewer.vm as any;

        fileViewerVm.overlayState.rect = { top: 100, left: 200, width: 300, height: 400 };
        fileViewerVm.overlayState.image = { src: 'test.jpg', srcset: 'test.jpg 1x', sizes: '300px', alt: 'Test' };
        fileViewerVm.overlayState.isFilled = false;
        fileViewerVm.overlayState.fillComplete = false;
        await wrapper.vm.$nextTick();

        let closeButton = wrapper.find('[data-test="close-overlay-button"]');
        expect(closeButton.exists()).toBe(false);

        fileViewerVm.overlayState.isFilled = true;
        fileViewerVm.overlayState.fillComplete = false;
        await wrapper.vm.$nextTick();

        closeButton = wrapper.find('[data-test="close-overlay-button"]');
        expect(closeButton.exists()).toBe(false);

        fileViewerVm.overlayState.fillComplete = true;
        fileViewerVm.overlayState.isClosing = false;
        await wrapper.vm.$nextTick();

        closeButton = wrapper.find('[data-test="close-overlay-button"]');
        expect(closeButton.exists()).toBe(true);

        fileViewerVm.overlayState.isClosing = true;
        await wrapper.vm.$nextTick();

        closeButton = wrapper.find('[data-test="close-overlay-button"]');
        expect(closeButton.exists()).toBe(false);
    });

    it('animates overlay scale to 0 when closing', async () => {
        const { wrapper } = await setupOverlayTest();

        const fileViewer = wrapper.findComponent(FileViewer);
        const fileViewerVm = fileViewer.vm as any;

        fileViewerVm.overlayState.rect = { top: 100, left: 200, width: 300, height: 400 };
        fileViewerVm.overlayState.image = { src: 'test.jpg', srcset: 'test.jpg 1x', sizes: '300px', alt: 'Test' };
        fileViewerVm.overlayState.isFilled = true;
        fileViewerVm.overlayState.fillComplete = true;
        fileViewerVm.overlayState.scale = 1;
        await wrapper.vm.$nextTick();

        expect(fileViewerVm.overlayState.scale).toBe(1);
        const overlay = wrapper.find('.border-smart-blue-500');
        expect(overlay.exists()).toBe(true);
        const overlayStyle = overlay.attributes('style') || '';
        expect(overlayStyle).toContain('scale(1)');

        fileViewerVm.closeOverlay();
        await wrapper.vm.$nextTick();

        expect(fileViewerVm.overlayState.scale).toBe(0);
        expect(fileViewerVm.overlayState.isClosing).toBe(true);
        await wrapper.vm.$nextTick();

        const updatedOverlay = wrapper.find('.border-smart-blue-500');
        const updatedStyle = updatedOverlay.attributes('style') || '';
        expect(updatedStyle).toContain('scale(0)');
    });

    it('has overflow hidden during closing animation', async () => {
        const { wrapper } = await setupOverlayTest();

        const fileViewer = wrapper.findComponent(FileViewer);
        const fileViewerVm = fileViewer.vm as any;

        fileViewerVm.overlayState.rect = { top: 0, left: 0, width: 1920, height: 1080 };
        fileViewerVm.overlayState.image = { src: 'test.jpg', srcset: 'test.jpg 1x', sizes: '300px', alt: 'Test' };
        fileViewerVm.overlayState.isFilled = true;
        fileViewerVm.overlayState.isClosing = false;
        await wrapper.vm.$nextTick();

        let overlay = wrapper.find('.border-smart-blue-500');
        expect(overlay.exists()).toBe(true);
        expect(overlay.classes()).toContain('overflow-hidden');

        fileViewerVm.overlayState.isClosing = true;
        await wrapper.vm.$nextTick();

        overlay = wrapper.find('.border-smart-blue-500');
        expect(overlay.exists()).toBe(true);
        expect(overlay.classes()).toContain('overflow-hidden');
    });

    it('has correct border styling', async () => {
        const { wrapper } = await setupOverlayTest();

        const fileViewer = wrapper.findComponent(FileViewer);
        const fileViewerVm = fileViewer.vm as any;

        fileViewerVm.overlayState.rect = { top: 0, left: 0, width: 1920, height: 1080 };
        fileViewerVm.overlayState.image = { src: 'test.jpg', srcset: 'test.jpg 1x', sizes: '300px', alt: 'Test' };
        fileViewerVm.overlayState.isFilled = true;
        await wrapper.vm.$nextTick();

        const overlay = wrapper.find('.border-smart-blue-500');
        expect(overlay.exists()).toBe(true);
        expect(overlay.classes()).toContain('border-4');
        expect(overlay.classes()).toContain('border-smart-blue-500');
    });
});



