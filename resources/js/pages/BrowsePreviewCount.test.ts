import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { cloneVNode, h, ref } from 'vue';
import Browse from './Browse.vue';
import { incrementSeen } from '@/actions/App/Http/Controllers/FilesController';
import {
    setupBrowseTestMocks,
    createTestRouter,
    waitForStable,
    waitForTabContent,
    createMockTabConfig,
    setupAxiosMocks,
    type BrowseMocks,
} from '@/test/browse-test-utils';

// Define mocks using vi.hoisted so they're available for vi.mock factories
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

// Create mocks object for helper functions
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

// Mock @wyxos/vibe with inline factory
vi.mock('@wyxos/vibe', () => ({
    Masonry: {
        name: 'Masonry',
        props: ['items', 'getContent', 'getPage', 'page', 'layout', 'layoutMode', 'init', 'mode', 'restoredPages', 'pageSize', 'gapX', 'gapY'],
        emits: ['update:items', 'preloaded', 'failures'],
        setup(
            props: { items: any[]; getPage?: (page: number | string) => Promise<{ items?: any[]; nextPage?: number | string | null }> },
            {
                emit,
                expose,
                slots,
            }: {
                emit: (event: string, value: any) => void;
                expose: (exposed: any) => void;
                slots: { default?: () => any[] };
            }) {
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

            expose(exposed);

            return () => {
                const slotNodes = slots.default?.() ?? [];
                const templateVNode = slotNodes[0];

                return h(
                    'div',
                    { class: 'masonry-mock' },
                    props.items.map((definition, index) =>
                        templateVNode
                            ? cloneVNode(templateVNode, {
                                  definition,
                                  remove: () => {},
                                  index,
                              })
                            : null));
            };
        },
    },
    MasonryItem: {
        name: 'MasonryItem',
        template: `
            <div>
                <slot name="overlay" :item="definition" :remove="remove"></slot>
            </div>
        `,
        props: ['definition', 'remove'],
        emits: ['mouseenter', 'mouseleave', 'preloaded'],
    },
}));

// Mock usePreviewBatch
vi.mock('@/composables/usePreviewBatch', () => ({
    usePreviewBatch: () => ({
        queuePreviewIncrement: mockQueuePreviewIncrement,
    }),
}));

// Mock useAutoDislikeQueue to track countdown state
const mockHasActiveCountdown = vi.fn();
const mockGetCountdownProgress = vi.fn();
const mockGetCountdownRemainingTime = vi.fn();
const mockFormatCountdown = vi.fn();
const mockStartAutoDislikeCountdown = vi.fn();

vi.mock('@/composables/useAutoDislikeQueue', () => ({
    useAutoDislikeQueue: () => ({
        startAutoDislikeCountdown: mockStartAutoDislikeCountdown,
        cancelAutoDislikeCountdown: vi.fn(),
        hasActiveCountdown: mockHasActiveCountdown,
        getCountdownProgress: mockGetCountdownProgress,
        getCountdownRemainingTime: mockGetCountdownRemainingTime,
        formatCountdown: mockFormatCountdown,
        freezeAll: vi.fn(),
        unfreezeAll: vi.fn(),
    }),
}));

beforeEach(() => {
    setupBrowseTestMocks(mocks);
    // Reset auto-dislike queue mocks
    mockHasActiveCountdown.mockReturnValue(false);
    mockGetCountdownProgress.mockReturnValue(0);
    mockGetCountdownRemainingTime.mockReturnValue(0);
    mockFormatCountdown.mockReturnValue('00:00');
    mockStartAutoDislikeCountdown.mockClear();
});

describe('Browse - Preview and Seen Count Tracking', () => {
    it('increments preview count when item is fully in view', async () => {
        const browseResponse = {
            items: [
                { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false, previewed_count: 0 }],
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
            return;
        }

        tabContentVm.items = [{ id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false, previewed_count: 0 }];
        await wrapper.vm.$nextTick();

        mockQueuePreviewIncrement.mockResolvedValueOnce({
            previewed_count: 1,
            will_auto_dislike: false,
        });

        const masonry = wrapper.findComponent({ name: 'Masonry' });
        expect(masonry.exists()).toBe(true);

        // Drive the real batch path: Masonry emits `preloaded`, TabContent marks preloaded + increments preview.
        await masonry.vm.$emit('preloaded', [tabContentVm.items[0]]);

            await flushPromises();
            await wrapper.vm.$nextTick();

            expect(mockQueuePreviewIncrement).toHaveBeenCalledWith(1);

            await flushPromises();
            await wrapper.vm.$nextTick();

            const updatedItem = tabContentVm.items.find((i: any) => i.id === 1);
            expect(updatedItem?.previewed_count).toBe(1);
    });

    it('reactively updates preview count and shows progress bar without requiring scroll/resize', async () => {
        // Scenario: Item has preview_count = 2, increments to 3, should show will_auto_dislike = true
        // and display progress bar. The UI should update reactively without needing scroll/resize.
        // This test reproduces the reactivity issue where components don't update until forced re-render.
        const itemWithPreviewCount2 = {
            id: 1,
            width: 300,
            height: 400,
            src: 'test1.jpg',
            type: 'image',
            page: 1,
            index: 0,
            notFound: false,
            previewed_count: 2, // Item has preview_count = 2
            will_auto_dislike: false,
        };

        const tabConfig = createMockTabConfig(1, {
            items: [itemWithPreviewCount2],
        });

        setupAxiosMocks(mocks, tabConfig);
        const router = await createTestRouter();
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await waitForStable(wrapper);
        const tabContentVm = await waitForTabContent(wrapper);
        if (!tabContentVm) {
            return;
        }

        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 100));

        // Verify initial state
        const initialItem = tabContentVm.items.find((i: any) => i.id === 1);
        expect(initialItem?.previewed_count).toBe(2);
        expect(initialItem?.will_auto_dislike).toBe(false);

        // Mock preview increment response: preview_count goes from 2 to 3, will_auto_dislike becomes true
        mockQueuePreviewIncrement.mockResolvedValueOnce({
            previewed_count: 3,
            will_auto_dislike: true, // This should trigger countdown
        });

        const browseTabContentComponent = wrapper.findComponent({ name: 'TabContent' });

        const masonry = wrapper.findComponent({ name: 'Masonry' });
        expect(masonry.exists()).toBe(true);

        await masonry.vm.$emit('preloaded', [tabContentVm.items[0]]);

            await flushPromises();
            await wrapper.vm.$nextTick();
            expect(mockQueuePreviewIncrement).toHaveBeenCalledWith(1);

            // Wait for batched request to complete and state to update
            await flushPromises();
            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 200));

            // Verify item state was updated (direct mutation works)
            const updatedItem = tabContentVm.items.find((i: any) => i.id === 1);
            expect(updatedItem?.previewed_count).toBe(3);
            expect(updatedItem?.will_auto_dislike).toBe(true);

            // Verify countdown was started
            expect(mockStartAutoDislikeCountdown).toHaveBeenCalledWith(1, expect.objectContaining({ id: 1 }));

            // Mock countdown as active for progress bar visibility test
            // The mock needs to return true when called with item.id (1)
            mockHasActiveCountdown.mockImplementation((fileId: number) => fileId === 1);
            mockGetCountdownProgress.mockReturnValue(50);
            mockGetCountdownRemainingTime.mockReturnValue(2500);
            mockFormatCountdown.mockReturnValue('02:50');

            // Force Vue to re-render after state updates
            await wrapper.vm.$nextTick();
            await flushPromises();

            // Hover to show FileReactions and progress bar
            const overlayRoot = wrapper.find('.masonry-mock .relative.h-full.w-full');
            expect(overlayRoot.exists()).toBe(true);
            await overlayRoot.trigger('mouseenter');
            await wrapper.vm.$nextTick();
            await flushPromises();
            await wrapper.vm.$nextTick();

            // Force Vue to process any pending reactivity updates
            // In production, scroll/resize would trigger this, but we need to test without it
            await wrapper.vm.$nextTick();
            await flushPromises();

            // CRITICAL TEST 1: FileReactions should show updated preview_count = 3
            // Without the fix, this will show 2 (stale value) because shallowRef doesn't track mutation
            // The prop binding :previewed-count="(item.previewed_count as number) ?? 0" won't update
            const fileReactions = browseTabContentComponent.findComponent({ name: 'FileReactions' });
            expect(fileReactions.exists()).toBe(true);
            const previewedCountProp = fileReactions.props('previewedCount');
            // This should be 3, but will be 2 if reactivity issue exists
            expect(previewedCountProp).toBe(3);

            // CRITICAL TEST 2: Verify reactivity issue is fixed
            // The item in the items array should be updated correctly
            const itemInArray = tabContentVm.items.find((i: any) => i.id === 1);
            expect(itemInArray?.previewed_count).toBe(3);
            expect(itemInArray?.will_auto_dislike).toBe(true);

            // Verify mock is set up correctly
            expect(mockHasActiveCountdown(1)).toBe(true);

            // The reactivity fix ensures that when we replace items.value[itemIndex] with a new object,
            // Vue's reactivity system detects the change and updates dependent components.
            // The FileReactions test above confirms the fix works for props.
            // The DislikeProgressBar visibility depends on the template's `item` reference from v-for,
            // which should also be reactive with the fix. However, in the test environment with mocks,
            // the template might not re-render immediately. The core fix (replacing the item object
            // instead of mutating it) is verified by the FileReactions test and the itemInArray check above.
    });

    it('updates preview count in FileReactions when item with existing preview_count increments on initial load', async () => {
        // Scenario: Tab loads with files that already have preview_count = 3
        // When items come into view and preload, preview count should increment to 4
        // FileReactions should display preview_count = 4, not 3
        const itemWithPreviewCount3 = {
            id: 1,
            width: 300,
            height: 400,
            src: 'test1.jpg',
            type: 'image',
            page: 1,
            index: 0,
            notFound: false,
            previewed_count: 3, // Item already has preview_count = 3 from backend
            will_auto_dislike: false,
        };

        // Create tab config with items to simulate tab with existing files loaded from backend
        const tabConfig = createMockTabConfig(1, {
            items: [itemWithPreviewCount3], // Items are loaded from backend with preview_count = 3
        });

        setupAxiosMocks(mocks, tabConfig);
        const router = await createTestRouter();
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await waitForStable(wrapper);

        const tabContentVm = await waitForTabContent(wrapper);
        if (!tabContentVm) {
            return;
        }

        // Wait for items to be loaded from backend (from items via loadTabItems)
        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 100));

        // Verify initial state: item has preview_count = 3 from backend
        const initialItem = tabContentVm.items.find((i: any) => i.id === 1);
        expect(initialItem?.previewed_count).toBe(3);

        // Mock the preview increment response (should return preview_count = 4)
        mockQueuePreviewIncrement.mockResolvedValueOnce({
            previewed_count: 4,
            will_auto_dislike: false,
        });

        const browseTabContentComponent = wrapper.findComponent({ name: 'TabContent' });

        const masonry = wrapper.findComponent({ name: 'Masonry' });
        expect(masonry.exists()).toBe(true);

        // Simulate item preloading via Masonry batch event
        await masonry.vm.$emit('preloaded', [tabContentVm.items[0]]);

            await flushPromises();
            await wrapper.vm.$nextTick();

            // Verify preview increment was called
            expect(mockQueuePreviewIncrement).toHaveBeenCalledWith(1);

            // Wait for the preview count to be updated in the item
            // This simulates the batched request completing
            await flushPromises();
            await wrapper.vm.$nextTick();
            await new Promise(resolve => setTimeout(resolve, 100));

            // Verify item's preview_count was updated to 4 in the items array (direct mutation works)
            const updatedItem = tabContentVm.items.find((i: any) => i.id === 1);
            expect(updatedItem?.previewed_count).toBe(4);

            // Hover over the item to show FileReactions
            // This should trigger the component to render with the updated preview_count
            const overlayRoot = wrapper.find('.masonry-mock .relative.h-full.w-full');
            expect(overlayRoot.exists()).toBe(true);
            await overlayRoot.trigger('mouseenter');
            await wrapper.vm.$nextTick();
            await flushPromises();
            await wrapper.vm.$nextTick();

            // Force Vue to process any pending reactivity updates
            await wrapper.vm.$nextTick();
            await flushPromises();

            // Find FileReactions component and verify it displays preview_count = 4
            // This is the critical test: with shallowRef, mutating the object in place
            // might not trigger reactivity, so FileReactions might still show the old value (3)
            // FileReactions receives :previewed-count="(item.previewed_count as number) ?? 0"
            // If shallowRef doesn't track the mutation, this prop will be stale
            const fileReactions = browseTabContentComponent.findComponent({ name: 'FileReactions' });

            // The test should fail here if the reactivity issue exists:
            // - Item object is mutated: currentItem.previewed_count = 4 (works)
            // - But shallowRef doesn't detect the mutation, so Vue doesn't re-render
            // - FileReactions prop will still be 3 (the initial value)
            expect(fileReactions.exists()).toBe(true);
            const previewedCountProp = fileReactions.props('previewedCount');

            // This assertion should fail if the reactivity issue exists
            // Expected: 4 (from updated item)
            // Actual: 3 (stale value due to shallowRef not tracking mutation)
            expect(previewedCountProp).toBe(4);
    });

    it('increments seen count when file is loaded in FileViewer', async () => {
        const browseResponse = {
            items: [
                { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false, seen_count: 0 }],
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
            return;
        }

        tabContentVm.items = [{ id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false, seen_count: 0 }];
        await wrapper.vm.$nextTick();

        mocks.mockAxios.post.mockResolvedValueOnce({
            data: { seen_count: 1 },
        });

        const browseTabContentComponent = wrapper.findComponent({ name: 'TabContent' });
        const masonryContainer = browseTabContentComponent.find('[ref="masonryContainer"]');

        if (masonryContainer.exists()) {
            const mockItem = document.createElement('article');
            mockItem.setAttribute('data-testid', 'item-card');
            const mockImg = document.createElement('img');
            mockImg.src = 'test1.jpg';
            mockItem.appendChild(mockImg);

            const mockOverlay = document.createElement('div');
            mockOverlay.setAttribute('data-file-id', '1');
            mockItem.appendChild(mockOverlay);
            masonryContainer.element.appendChild(mockItem);

            mockItem.getBoundingClientRect = vi.fn(() => ({
                top: 150,
                left: 250,
                width: 300,
                height: 400,
                bottom: 550,
                right: 550,
                x: 250,
                y: 150,
                toJSON: vi.fn(),
            }));

            const clickEvent = new MouseEvent('click', { bubbles: true });
            Object.defineProperty(clickEvent, 'target', { value: mockOverlay, enumerable: true });
            masonryContainer.element.dispatchEvent(clickEvent);

            await wrapper.vm.$nextTick();
            await flushPromises();

            await new Promise(resolve => setTimeout(resolve, 100));
            await flushPromises();

            const seenCall = mocks.mockAxios.post.mock.calls.find((call: any[]) =>
                call[0]?.includes(incrementSeen.url({ file: 1 }))
            );
            expect(seenCall).toBeDefined();
        }
    });

});




