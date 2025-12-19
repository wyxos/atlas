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

// Mock @wyxos/vibe
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

// Mock useContainerBlacklists
const mockCreateBlacklist = vi.fn();
const mockCheckBlacklist = vi.fn();
vi.mock('@/composables/useContainerBlacklists', () => ({
    useContainerBlacklists: () => ({
        blacklists: ref([]),
        isLoading: ref(false),
        error: ref(null),
        fetchBlacklists: vi.fn(),
        createBlacklist: mockCreateBlacklist,
        deleteBlacklist: vi.fn(),
        checkBlacklist: mockCheckBlacklist,
        isContainerBlacklisted: vi.fn(() => false),
        getBlacklistedContainerActionType: vi.fn(() => null),
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

describe('Browse - Container Blacklist Countdown Start', () => {
    it('starts countdown immediately when item has will_auto_dislike from blacklisted container with ui_countdown', async () => {
        const browseResponse = {
            items: [
                {
                    id: 1,
                    width: 300,
                    height: 400,
                    src: 'test1.jpg',
                    type: 'image',
                    page: 1,
                    index: 0,
                    notFound: false,
                    previewed_count: 0,
                    will_auto_dislike: true, // Set by backend when container is blacklisted with ui_countdown
                    containers: [
                        {
                            id: 100,
                            type: 'User',
                            source: 'CivitAI',
                            source_id: 'user123',
                            action_type: 'ui_countdown',
                            blacklisted_at: '2024-01-01T00:00:00Z',
                        },
                    ],
                },
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

        // Wait for items to be loaded
        await wrapper.vm.$nextTick();
        await flushPromises();

        // Manually set items to trigger the watch (items might not be loaded through API in test)
        tabContentVm.items = browseResponse.items;
        await wrapper.vm.$nextTick();
        await flushPromises();

        const browseTabContentComponent = wrapper.findComponent({ name: 'BrowseTabContent' });
        const autoDislikeQueue = (browseTabContentComponent.vm as any).autoDislikeQueue;

        // Verify item is queued
        expect(autoDislikeQueue.isQueued(1)).toBe(true);

        // Verify item is active (countdown should be running)
        expect(autoDislikeQueue.isActive(1)).toBe(true);

        // Verify countdown is running by checking remaining time decreases
        const initialRemaining = autoDislikeQueue.getRemaining(1);
        expect(initialRemaining).toBeGreaterThan(0);
        expect(initialRemaining).toBeLessThanOrEqual(5000); // Should be 5000ms or less

        // Advance time by 1 second
        vi.advanceTimersByTime(1000);
        vi.runOnlyPendingTimers();

        await wrapper.vm.$nextTick();

        // Verify remaining time has decreased
        const remainingAfter1s = autoDislikeQueue.getRemaining(1);
        expect(remainingAfter1s).toBeLessThan(initialRemaining);
        expect(remainingAfter1s).toBe(initialRemaining - 1000);
    });
});

