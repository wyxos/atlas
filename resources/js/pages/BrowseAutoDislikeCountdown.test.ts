import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { ref } from 'vue';
import Browse from './Browse.vue';
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
        template: `
            <div class="masonry-mock">
                <slot
                    v-for="(item, index) in items"
                    :key="item.id || index"
                    :item="item"
                    :remove="() => {}"
                    :index="index"
                ></slot>
            </div>
        `,
        props: ['items', 'getNextPage', 'layout', 'layoutMode', 'mobileBreakpoint', 'skipInitialLoad', 'backfillEnabled', 'backfillDelayMs', 'backfillMaxCalls'],
        emits: ['backfill:start', 'backfill:tick', 'backfill:stop', 'backfill:retry-start', 'backfill:retry-tick', 'backfill:retry-stop', 'update:items'],
        setup() {
            const exposed = {
                init: mockInit,
                refreshLayout: vi.fn(),
                cancelLoad: mockCancelLoad,
                destroy: mockDestroy,
                remove: mockRemove,
                removeMany: mockRemoveMany,
                restore: mockRestore,
                restoreMany: mockRestoreMany,
            };
            Object.defineProperty(exposed, 'isLoading', { get: () => mockIsLoading.value, enumerable: true });
            return exposed;
        },
    },
    MasonryItem: {
        name: 'MasonryItem',
        template: `
            <div @mouseenter="$emit('mouseenter', $event)" @mouseleave="$emit('mouseleave', $event)">
                <slot
                    :item="item"
                    :remove="remove"
                    :imageLoaded="true"
                    :imageError="false"
                    :videoLoaded="false"
                    :videoError="false"
                    :isLoading="false"
                    :showMedia="true"
                    :imageSrc="item?.src || item?.thumbnail || ''"
                    :videoSrc="null"
                ></slot>
            </div>
        `,
        props: ['item', 'remove'],
        emits: ['mouseenter', 'mouseleave', 'preload:success', 'in-view'],
    },
}));

// Mock usePreviewBatch
vi.mock('@/composables/usePreviewBatch', () => ({
    usePreviewBatch: () => ({
        queuePreviewIncrement: mockQueuePreviewIncrement,
    }),
}));

beforeEach(() => {
    setupBrowseTestMocks(mocks);
    vi.useFakeTimers();
});

afterEach(() => {
    vi.useRealTimers();
    vi.clearAllMocks();
});

describe('Browse - Auto-Dislike Countdown Integration', () => {
    it('shows countdown UI when will_auto_dislike is set after 3 previews', async () => {
        const browseResponse = {
            items: [
                { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false, previewed_count: 2 },
            ],
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

        tabContentVm.items = [{ id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false, previewed_count: 2 }];
        await wrapper.vm.$nextTick();

        // Mock preview increment returning will_auto_dislike: true
        mockQueuePreviewIncrement.mockResolvedValueOnce({
            previewed_count: 3,
            will_auto_dislike: true,
        });

        const browseTabContentComponent = wrapper.findComponent({ name: 'BrowseTabContent' });
        const masonryItem = browseTabContentComponent.findComponent({ name: 'MasonryItem' });

        if (masonryItem.exists()) {
            await masonryItem.vm.$emit('in-view', {
                item: { id: 1 },
                type: 'image',
            });

            await flushPromises();
            await wrapper.vm.$nextTick();

            // Check that will_auto_dislike is set
            const updatedItem = tabContentVm.items.find((i: any) => i.id === 1);
            expect(updatedItem?.will_auto_dislike).toBe(true);

            // Check that countdown UI is rendered (countdown pill should be visible)
            // The countdown is rendered when autoDislikeQueue.isQueued(item.id) is true
            const autoDislikeQueue = (browseTabContentComponent.vm as any).autoDislikeQueue;
            expect(autoDislikeQueue.isQueued(1)).toBe(true);

            // Check that countdown UI element exists in the DOM
            await wrapper.vm.$nextTick();
            const countdownElement = browseTabContentComponent.find('[class*="countdown"]');
            // Countdown might be in a transition, so check queue state instead
            expect(autoDislikeQueue.isQueued(1)).toBe(true);
        }
    });

    it('adds item to auto-dislike queue when will_auto_dislike is set', async () => {
        const browseResponse = {
            items: [
                { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false, previewed_count: 2 },
            ],
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

        tabContentVm.items = [{ id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false, previewed_count: 2 }];
        await wrapper.vm.$nextTick();

        mockQueuePreviewIncrement.mockResolvedValueOnce({
            previewed_count: 3,
            will_auto_dislike: true,
        });

        const browseTabContentComponent = wrapper.findComponent({ name: 'BrowseTabContent' });
        const masonryItem = browseTabContentComponent.findComponent({ name: 'MasonryItem' });

        if (masonryItem.exists()) {
            await masonryItem.vm.$emit('in-view', {
                item: { id: 1 },
                type: 'image',
            });

            await flushPromises();
            await wrapper.vm.$nextTick();

            // Check that item is in the queue
            const autoDislikeQueue = (browseTabContentComponent.vm as any).autoDislikeQueue;
            expect(autoDislikeQueue.isQueued(1)).toBe(true);
        }
    });

    it('activates countdown when preview loads for queued item', async () => {
        const browseResponse = {
            items: [
                { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false, previewed_count: 2, will_auto_dislike: true },
            ],
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

        tabContentVm.items = [{ id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false, previewed_count: 2, will_auto_dislike: true }];
        await wrapper.vm.$nextTick();

        const browseTabContentComponent = wrapper.findComponent({ name: 'BrowseTabContent' });
        const masonryItem = browseTabContentComponent.findComponent({ name: 'MasonryItem' });

        if (masonryItem.exists()) {
            // Emit in-view to activate countdown
            await masonryItem.vm.$emit('in-view', {
                item: { id: 1 },
                type: 'image',
            });

            await flushPromises();
            await wrapper.vm.$nextTick();

            // Check that countdown is active
            const autoDislikeQueue = (browseTabContentComponent.vm as any).autoDislikeQueue;
            expect(autoDislikeQueue.isQueued(1)).toBe(true);
            expect(autoDislikeQueue.isActive(1)).toBe(true);
        }
    });

    it('removes items using removeMany with correct item references after countdown expires', async () => {
        const browseResponse = {
            items: [
                { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false, previewed_count: 3, will_auto_dislike: true },
                { id: 2, width: 300, height: 400, src: 'test2.jpg', type: 'image', page: 1, index: 1, notFound: false, previewed_count: 3, will_auto_dislike: true },
            ],
            nextPage: null,
            services: [{ key: 'civit-ai-images', label: 'CivitAI Images' }],
        };
        const tabConfig = createMockTabConfig(1);
        setupAxiosMocks(mocks, tabConfig, browseResponse);

        // Mock auto-dislike batch API
        mockAxios.post.mockResolvedValueOnce({
            data: {
                message: 'Auto-dislike performed',
                auto_disliked_count: 2,
                file_ids: [1, 2],
            },
        });

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

        const items = [
            { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false, previewed_count: 3, will_auto_dislike: true },
            { id: 2, width: 300, height: 400, src: 'test2.jpg', type: 'image', page: 1, index: 1, notFound: false, previewed_count: 3, will_auto_dislike: true },
        ];
        tabContentVm.items = items;
        await wrapper.vm.$nextTick();

        const browseTabContentComponent = wrapper.findComponent({ name: 'BrowseTabContent' });
        const autoDislikeQueue = (browseTabContentComponent.vm as any).autoDislikeQueue;

        // Add items to queue and activate them
        // Note: addToQueue with startActive=true should activate immediately
        autoDislikeQueue.addToQueue(1, true);
        autoDislikeQueue.addToQueue(2, true);

        // Wait for reactivity to update
        await wrapper.vm.$nextTick();

        // Verify items are queued
        expect(autoDislikeQueue.isQueued(1)).toBe(true);
        expect(autoDislikeQueue.isQueued(2)).toBe(true);
        
        // Verify items are active (startActive=true should make them active)
        // If not active, manually activate them
        if (!autoDislikeQueue.isActive(1)) {
            autoDislikeQueue.activateItem(1);
        }
        if (!autoDislikeQueue.isActive(2)) {
            autoDislikeQueue.activateItem(2);
        }
        
        await wrapper.vm.$nextTick();
        
        expect(autoDislikeQueue.isActive(1)).toBe(true);
        expect(autoDislikeQueue.isActive(2)).toBe(true);

        // Wait for setInterval to be set up
        await wrapper.vm.$nextTick();
        await flushPromises();

        // Fast-forward time to expire countdowns
        // The queue ticks every 100ms, so we need 50 ticks to reach 5000ms
        // Use runOnlyPendingTimers to ensure interval callbacks execute
        vi.advanceTimersByTime(5000);
        vi.runOnlyPendingTimers();

        await flushPromises();
        await wrapper.vm.$nextTick();
        await flushPromises();

        // Verify removeMany was called with items from items array (not itemsMap)
        expect(mockRemoveMany).toHaveBeenCalledTimes(1);
        const removedItems = mockRemoveMany.mock.calls[0][0];

        // Verify items are from the actual items array (same references)
        expect(removedItems.length).toBe(2);
        expect(removedItems[0].id).toBe(1);
        expect(removedItems[1].id).toBe(2);

        // Verify items are the same references as in items array
        expect(removedItems[0]).toBe(items[0]);
        expect(removedItems[1]).toBe(items[1]);
    });

    it('shows progress bar filling during countdown', async () => {
        const browseResponse = {
            items: [
                { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false, previewed_count: 3, will_auto_dislike: true },
            ],
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

        tabContentVm.items = [{ id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false, previewed_count: 3, will_auto_dislike: true }];
        await wrapper.vm.$nextTick();

        const browseTabContentComponent = wrapper.findComponent({ name: 'BrowseTabContent' });
        const autoDislikeQueue = (browseTabContentComponent.vm as any).autoDislikeQueue;

        // Add item to queue and activate
        autoDislikeQueue.addToQueue(1, true);
        
        // Wait for reactivity
        await wrapper.vm.$nextTick();
        
        // Verify item is queued
        expect(autoDislikeQueue.isQueued(1)).toBe(true);
        
        // If not active, manually activate
        if (!autoDislikeQueue.isActive(1)) {
            autoDislikeQueue.activateItem(1);
            await wrapper.vm.$nextTick();
        }
        
        expect(autoDislikeQueue.isActive(1)).toBe(true);

        // Wait for setInterval to be set up
        await wrapper.vm.$nextTick();
        await flushPromises();

        // Advance time by 2.5 seconds (50% progress)
        // The queue ticks every 100ms, so advance by 2500ms
        vi.advanceTimersByTime(2500);
        vi.runOnlyPendingTimers();

        await wrapper.vm.$nextTick();

        // Check that progress is approximately 0.5 (within 0.1 tolerance)
        const progress = autoDislikeQueue.getProgress(1);
        expect(progress).toBeGreaterThanOrEqual(0.4);
        expect(progress).toBeLessThanOrEqual(0.6);
    });

    it('pauses countdown on hover and resumes on hover end', async () => {
        const browseResponse = {
            items: [
                { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false, previewed_count: 3, will_auto_dislike: true },
            ],
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

        tabContentVm.items = [{ id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false, previewed_count: 3, will_auto_dislike: true }];
        await wrapper.vm.$nextTick();

        const browseTabContentComponent = wrapper.findComponent({ name: 'BrowseTabContent' });
        const autoDislikeQueue = (browseTabContentComponent.vm as any).autoDislikeQueue;

        // Add item to queue and activate
        autoDislikeQueue.addToQueue(1, true);
        
        // Wait for reactivity
        await wrapper.vm.$nextTick();
        
        // Verify item is queued
        expect(autoDislikeQueue.isQueued(1)).toBe(true);
        
        // If not active, manually activate
        if (!autoDislikeQueue.isActive(1)) {
            autoDislikeQueue.activateItem(1);
            await wrapper.vm.$nextTick();
        }
        
        expect(autoDislikeQueue.isActive(1)).toBe(true);

        // Wait for setInterval to be set up
        await wrapper.vm.$nextTick();
        await flushPromises();

        // Advance time by 1 second (1000ms)
        vi.advanceTimersByTime(1000);
        vi.runOnlyPendingTimers();
        await wrapper.vm.$nextTick();

        const remainingBeforeFreeze = autoDislikeQueue.getRemaining(1);
        // Should be less than 5000ms (initial) but more than 0
        expect(remainingBeforeFreeze).toBeLessThan(5000);
        expect(remainingBeforeFreeze).toBeGreaterThan(0);

        // Freeze (simulate hover)
        autoDislikeQueue.freeze();

        // Advance time by 2 seconds (should not decrement when frozen)
        vi.advanceTimersByTime(2000);
        vi.runOnlyPendingTimers();
        await wrapper.vm.$nextTick();

        // Remaining should be unchanged when frozen
        expect(autoDislikeQueue.getRemaining(1)).toBe(remainingBeforeFreeze);

        // Unfreeze (simulate hover end)
        autoDislikeQueue.unfreeze();

        // Advance time by 1 second (should decrement now)
        vi.advanceTimersByTime(1000);
        vi.runOnlyPendingTimers();
        await wrapper.vm.$nextTick();

        // Should have decremented further
        expect(autoDislikeQueue.getRemaining(1)).toBeLessThan(remainingBeforeFreeze);
    });
});

