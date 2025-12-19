import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { ref } from 'vue';
import Browse from './Browse.vue';
import FileViewer from '../components/FileViewer.vue';
import { useReactionQueue } from '../composables/useReactionQueue';
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
    mockDestroy,
    mockInit,
    mockRemove,
    mockRemoveMany,
    mockRestore,
    mockRestoreMany,
    mockQueuePreviewIncrement,
} = vi.hoisted(() => ({
    mockAxios: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), patch: vi.fn() },
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
        props: ['items', 'getNextPage', 'layout', 'layoutMode', 'mobileBreakpoint', 'skipInitialLoad', 'backfillEnabled', 'backfillDelayMs', 'backfillMaxCalls'],
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

function getFileViewer(wrapper: any) {
    const browseTabContent = wrapper.findComponent({ name: 'BrowseTabContent' });
    if (browseTabContent.exists()) {
        const fileViewer = browseTabContent.findComponent(FileViewer);
        if (fileViewer.exists()) return fileViewer;
    }
    return null;
}

describe('Browse - ALT + Click Reactions', () => {
    it('triggers like reaction when ALT + Left Click on masonry item', async () => {
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

        const vm = wrapper.vm as any;

        const tabContentVm = await waitForTabContent(wrapper);
        expect(tabContentVm).toBeDefined();
        if (!tabContentVm) return;

        tabContentVm.items = [{ id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false }];
        await wrapper.vm.$nextTick();

        const browseTabContentComponent = wrapper.findComponent({ name: 'BrowseTabContent' });
        const masonryContainer = browseTabContentComponent.find('[ref="masonryContainer"]');
        if (masonryContainer.exists()) {
            const mockItem = document.createElement('div');
            mockItem.className = 'masonry-item';
            mockItem.setAttribute('data-key', '1');
            const mockImg = document.createElement('img');
            mockImg.src = 'test1.jpg';
            mockItem.appendChild(mockImg);
            masonryContainer.element.appendChild(mockItem);

            const clickEvent = new MouseEvent('click', { bubbles: true, altKey: true, button: 0 });
            Object.defineProperty(clickEvent, 'target', { value: mockImg, enumerable: true });
            masonryContainer.element.dispatchEvent(clickEvent);

            await flushPromises();
            await wrapper.vm.$nextTick();

            const { queuedReactions } = useReactionQueue();
            expect(queuedReactions.value.length).toBe(1);
            expect(queuedReactions.value[0].fileId).toBe(1);
            expect(queuedReactions.value[0].type).toBe('like');

            const fileViewer = wrapper.findComponent(FileViewer);
            const fileViewerVm = fileViewer.vm as any;
            expect(fileViewerVm.overlayRect).toBeNull();
        }
    });

    it('triggers dislike reaction when ALT + Right Click on masonry item', async () => {
        const browseResponse = {
            items: [{ id: 2, width: 300, height: 400, src: 'test2.jpg', type: 'image', page: 1, index: 0, notFound: false }],
            nextPage: null,
            services: [{ key: 'civit-ai-images', label: 'CivitAI Images' }],
        };
        const tabConfig = createMockTabConfig(1);
        setupAxiosMocks(mocks, tabConfig, browseResponse);
        const router = await createTestRouter();
        const wrapper = mount(Browse, { global: { plugins: [router] } });

        await waitForStable(wrapper);

        const vm = wrapper.vm as any;

        const tabContentVm = await waitForTabContent(wrapper);
        expect(tabContentVm).toBeDefined();
        if (!tabContentVm) return;

        tabContentVm.items = [{ id: 2, width: 300, height: 400, src: 'test2.jpg', type: 'image', page: 1, index: 0, notFound: false }];
        await wrapper.vm.$nextTick();

        const browseTabContentComponent = wrapper.findComponent({ name: 'BrowseTabContent' });
        const masonryContainer = browseTabContentComponent.find('[ref="masonryContainer"]');
        if (masonryContainer.exists()) {
            const mockItem = document.createElement('div');
            mockItem.className = 'masonry-item';
            mockItem.setAttribute('data-key', '2');
            const mockImg = document.createElement('img');
            mockImg.src = 'test2.jpg';
            mockItem.appendChild(mockImg);
            masonryContainer.element.appendChild(mockItem);

            const contextMenuEvent = new MouseEvent('contextmenu', { bubbles: true, altKey: true, button: 2 });
            Object.defineProperty(contextMenuEvent, 'target', { value: mockImg, enumerable: true });
            masonryContainer.element.dispatchEvent(contextMenuEvent);

            await flushPromises();
            await wrapper.vm.$nextTick();

            const { queuedReactions } = useReactionQueue();
            expect(queuedReactions.value.length).toBe(1);
            expect(queuedReactions.value[0].fileId).toBe(2);
            expect(queuedReactions.value[0].type).toBe('dislike');
        }
    });

    it('triggers love reaction when ALT + Middle Click on masonry item', async () => {
        const browseResponse = {
            items: [{ id: 3, width: 300, height: 400, src: 'test3.jpg', type: 'image', page: 1, index: 0, notFound: false }],
            nextPage: null,
            services: [{ key: 'civit-ai-images', label: 'CivitAI Images' }],
        };
        const tabConfig = createMockTabConfig(1);
        setupAxiosMocks(mocks, tabConfig, browseResponse);
        const router = await createTestRouter();
        const wrapper = mount(Browse, { global: { plugins: [router] } });

        await waitForStable(wrapper);

        const vm = wrapper.vm as any;

        const tabContentVm = await waitForTabContent(wrapper);
        expect(tabContentVm).toBeDefined();
        if (!tabContentVm) return;

        tabContentVm.items = [{ id: 3, width: 300, height: 400, src: 'test3.jpg', type: 'image', page: 1, index: 0, notFound: false }];
        await wrapper.vm.$nextTick();

        const browseTabContentComponent = wrapper.findComponent({ name: 'BrowseTabContent' });
        const masonryContainer = browseTabContentComponent.find('[ref="masonryContainer"]');
        if (masonryContainer.exists()) {
            const mockItem = document.createElement('div');
            mockItem.className = 'masonry-item';
            mockItem.setAttribute('data-key', '3');
            const mockImg = document.createElement('img');
            mockImg.src = 'test3.jpg';
            mockItem.appendChild(mockImg);
            masonryContainer.element.appendChild(mockItem);

            const mouseDownEvent = new MouseEvent('mousedown', { bubbles: true, altKey: true, button: 1 });
            Object.defineProperty(mouseDownEvent, 'target', { value: mockImg, enumerable: true });
            masonryContainer.element.dispatchEvent(mouseDownEvent);

            await flushPromises();
            await wrapper.vm.$nextTick();

            const { queuedReactions } = useReactionQueue();
            expect(queuedReactions.value.length).toBe(1);
            expect(queuedReactions.value[0].fileId).toBe(3);
            expect(queuedReactions.value[0].type).toBe('love');
        }
    });

    it('triggers like reaction when ALT + Left Click on overlay image', async () => {
        const tabConfig = createMockTabConfig(1);
        setupAxiosMocks(mocks, tabConfig);
        const router = await createTestRouter();
        const wrapper = mount(Browse, { global: { plugins: [router] } });

        await waitForStable(wrapper);

        const vm = wrapper.vm as any;

        const tabContentVm = await waitForTabContent(wrapper);
        expect(tabContentVm).toBeDefined();
        if (!tabContentVm) return;

        tabContentVm.items = [
            { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false },
            { id: 2, width: 300, height: 400, src: 'test2.jpg', type: 'image', page: 1, index: 1, notFound: false },
        ];
        await wrapper.vm.$nextTick();

        const fileViewer = getFileViewer(wrapper);
        expect(fileViewer).toBeDefined();
        if (!fileViewer) return;

        const fileViewerVm = fileViewer.vm as any;

        fileViewerVm.overlayRect = { top: 0, left: 0, width: 800, height: 600 };
        fileViewerVm.overlayImage = { src: 'test1.jpg', alt: 'Test 1' };
        fileViewerVm.overlayIsFilled = true;
        fileViewerVm.overlayFillComplete = true;
        fileViewerVm.currentItemIndex = 0;
        fileViewerVm.items = tabContentVm.items;
        await wrapper.vm.$nextTick();

        const overlayImage = fileViewer.find('img[alt="Test 1"]');
        expect(overlayImage.exists()).toBe(true);

        const clickEvent = new MouseEvent('click', { bubbles: true, altKey: true, button: 0 });
        overlayImage.element.dispatchEvent(clickEvent);

        await flushPromises();
        await wrapper.vm.$nextTick();

        const { queuedReactions } = useReactionQueue();
        expect(queuedReactions.value.length).toBe(1);
        expect(queuedReactions.value[0].fileId).toBe(1);
        expect(queuedReactions.value[0].type).toBe('like');
    });

    it('triggers dislike reaction when ALT + Right Click on overlay image', async () => {
        const tabConfig = createMockTabConfig(1);
        setupAxiosMocks(mocks, tabConfig);
        const router = await createTestRouter();
        const wrapper = mount(Browse, { global: { plugins: [router] } });

        await waitForStable(wrapper);

        const vm = wrapper.vm as any;

        const tabContentVm = await waitForTabContent(wrapper);
        expect(tabContentVm).toBeDefined();
        if (!tabContentVm) return;

        tabContentVm.items = [
            { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false },
            { id: 2, width: 300, height: 400, src: 'test2.jpg', type: 'image', page: 1, index: 1, notFound: false },
        ];
        await wrapper.vm.$nextTick();

        const fileViewer = getFileViewer(wrapper);
        expect(fileViewer).toBeDefined();
        if (!fileViewer) return;

        const fileViewerVm = fileViewer.vm as any;

        fileViewerVm.overlayRect = { top: 0, left: 0, width: 800, height: 600 };
        fileViewerVm.overlayImage = { src: 'test1.jpg', alt: 'Test 1' };
        fileViewerVm.overlayIsFilled = true;
        fileViewerVm.overlayFillComplete = true;
        fileViewerVm.currentItemIndex = 0;
        fileViewerVm.items = tabContentVm.items;
        await wrapper.vm.$nextTick();

        const overlayImage = fileViewer.find('img[alt="Test 1"]');
        expect(overlayImage.exists()).toBe(true);

        const contextMenuEvent = new MouseEvent('contextmenu', { bubbles: true, altKey: true, button: 2 });
        overlayImage.element.dispatchEvent(contextMenuEvent);

        await flushPromises();
        await wrapper.vm.$nextTick();

        const { queuedReactions } = useReactionQueue();
        expect(queuedReactions.value.length).toBe(1);
        expect(queuedReactions.value[0].fileId).toBe(1);
        expect(queuedReactions.value[0].type).toBe('dislike');
    });

    it('triggers love reaction when ALT + Middle Click on overlay image', async () => {
        const tabConfig = createMockTabConfig(1);
        setupAxiosMocks(mocks, tabConfig);
        const router = await createTestRouter();
        const wrapper = mount(Browse, { global: { plugins: [router] } });

        await waitForStable(wrapper);

        const vm = wrapper.vm as any;

        const tabContentVm = await waitForTabContent(wrapper);
        expect(tabContentVm).toBeDefined();
        if (!tabContentVm) return;

        tabContentVm.items = [
            { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false },
            { id: 2, width: 300, height: 400, src: 'test2.jpg', type: 'image', page: 1, index: 1, notFound: false },
        ];
        await wrapper.vm.$nextTick();

        const fileViewer = getFileViewer(wrapper);
        expect(fileViewer).toBeDefined();
        if (!fileViewer) return;

        const fileViewerVm = fileViewer.vm as any;

        fileViewerVm.overlayRect = { top: 0, left: 0, width: 800, height: 600 };
        fileViewerVm.overlayImage = { src: 'test1.jpg', alt: 'Test 1' };
        fileViewerVm.overlayIsFilled = true;
        fileViewerVm.overlayFillComplete = true;
        fileViewerVm.currentItemIndex = 0;
        fileViewerVm.items = tabContentVm.items;
        await wrapper.vm.$nextTick();

        const overlayImage = fileViewer.find('img[alt="Test 1"]');
        expect(overlayImage.exists()).toBe(true);

        const mouseDownEvent = new MouseEvent('mousedown', { bubbles: true, altKey: true, button: 1 });
        overlayImage.element.dispatchEvent(mouseDownEvent);

        await flushPromises();
        await wrapper.vm.$nextTick();

        const { queuedReactions } = useReactionQueue();
        expect(queuedReactions.value.length).toBe(1);
        expect(queuedReactions.value[0].fileId).toBe(1);
        expect(queuedReactions.value[0].type).toBe('love');
    });

    it('does not trigger reaction when clicking without ALT key', async () => {
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

        const vm = wrapper.vm as any;

        const tabContentVm = await waitForTabContent(wrapper);
        expect(tabContentVm).toBeDefined();
        if (!tabContentVm) return;

        tabContentVm.items = [{ id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false }];
        await wrapper.vm.$nextTick();

        const browseTabContentComponent = wrapper.findComponent({ name: 'BrowseTabContent' });
        const masonryContainer = browseTabContentComponent.find('[ref="masonryContainer"]');
        if (masonryContainer.exists()) {
            const mockItem = document.createElement('div');
            mockItem.className = 'masonry-item';
            mockItem.setAttribute('data-key', '1');
            const mockImg = document.createElement('img');
            mockImg.src = 'test1.jpg';
            mockItem.appendChild(mockImg);
            masonryContainer.element.appendChild(mockItem);

            // Click WITHOUT altKey
            const clickEvent = new MouseEvent('click', { bubbles: true, altKey: false, button: 0 });
            Object.defineProperty(clickEvent, 'target', { value: mockImg, enumerable: true });
            masonryContainer.element.dispatchEvent(clickEvent);

            await flushPromises();
            await wrapper.vm.$nextTick();

            // No reaction should be queued when ALT key is not pressed
            const { queuedReactions } = useReactionQueue();
            expect(queuedReactions.value.length).toBe(0);
        }
    });
});
