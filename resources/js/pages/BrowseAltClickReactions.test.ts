import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { ref } from 'vue';
import Browse from './Browse.vue';
import FileViewer from '../components/FileViewer.vue';
import { store as fileReactionStore } from '@/actions/App/Http/Controllers/FileReactionController';
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
        props: ['items', 'getPage', 'layout', 'layoutMode', 'mobileBreakpoint', 'init', 'mode', 'backfillDelayMs', 'backfillMaxCalls'],
        emits: ['backfill:start', 'backfill:tick', 'backfill:stop', 'backfill:retry-start', 'backfill:retry-tick', 'backfill:retry-stop', 'update:items'],
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
                refreshLayout: vi.fn(),
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
    const browseTabContent = wrapper.findComponent({ name: 'TabContent' });
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

            // Verify API was called for reaction
            expect(mockAxios.post).toHaveBeenCalledWith(
                expect.stringContaining(fileReactionStore.url({ file: 1 })),
                expect.objectContaining({ type: 'like' })
            );

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


        const tabContentVm = await waitForTabContent(wrapper);
        expect(tabContentVm).toBeDefined();
        if (!tabContentVm) return;

        tabContentVm.items = [{ id: 2, width: 300, height: 400, src: 'test2.jpg', type: 'image', page: 1, index: 0, notFound: false }];
        await wrapper.vm.$nextTick();

        const browseTabContentComponent = wrapper.findComponent({ name: 'TabContent' });
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

            // Verify API was called for reaction
            expect(mockAxios.post).toHaveBeenCalledWith(
                expect.stringContaining(fileReactionStore.url({ file: 2 })),
                expect.objectContaining({ type: 'dislike' })
            );
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


        const tabContentVm = await waitForTabContent(wrapper);
        expect(tabContentVm).toBeDefined();
        if (!tabContentVm) return;

        tabContentVm.items = [{ id: 3, width: 300, height: 400, src: 'test3.jpg', type: 'image', page: 1, index: 0, notFound: false }];
        await wrapper.vm.$nextTick();

        const browseTabContentComponent = wrapper.findComponent({ name: 'TabContent' });
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

            // Verify API call was made directly (no queueing)
            expect(mockAxios.post).toHaveBeenCalledWith(
                expect.stringContaining(fileReactionStore.url({ file: 3 })),
                expect.objectContaining({ type: 'love' })
            );
        }
    });

    it('triggers like reaction when ALT + Left Click on overlay image', async () => {
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

        // Verify API was called for reaction
        expect(mockAxios.post).toHaveBeenCalledWith(
            expect.stringContaining('/api/files/1/reaction'),
            expect.objectContaining({ type: 'like' })
        );
    });

    it('triggers dislike reaction when ALT + Right Click on overlay image', async () => {
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

        // Verify API call was made directly (no queueing)
        expect(mockAxios.post).toHaveBeenCalledWith(
            expect.stringContaining('/api/files/1/reaction'),
            expect.objectContaining({ type: 'dislike' })
        );
    });

    it('triggers love reaction when ALT + Middle Click on overlay image', async () => {
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

        // Verify API call was made directly (no queueing)
        expect(mockAxios.post).toHaveBeenCalledWith(
            expect.stringContaining('/api/files/1/reaction'),
            expect.objectContaining({ type: 'love' })
        );
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

            // No reaction API call should be made when ALT key is not pressed
            expect(mockAxios.post).not.toHaveBeenCalled();
        }
    });
});
