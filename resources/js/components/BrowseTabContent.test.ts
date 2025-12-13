import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick, ref } from 'vue';
import BrowseTabContent from './BrowseTabContent.vue';
import type { MasonryItem } from '@/composables/useBrowseTabs';

// Mock axios
const mockAxios = {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    patch: vi.fn(),
};

// Mock window.axios
Object.defineProperty(window, 'axios', {
    value: mockAxios,
    writable: true,
});

// Mock @wyxos/vibe
const mockIsLoading = ref(false);
const mockCancelLoad = vi.fn();
const mockDestroy = vi.fn();
const mockInit = vi.fn();
const mockRemove = vi.fn();
const mockRefreshLayout = vi.fn();
const mockLoadPage = vi.fn();
const mockLoadNext = vi.fn();
const mockReset = vi.fn();

vi.mock('@wyxos/vibe', () => ({
    Masonry: {
        name: 'Masonry',
        template: `
            <div class="masonry-mock" data-test="masonry-component">
                <slot 
                    v-for="(item, index) in items" 
                    :key="item.id || index"
                    :item="item" 
                    :remove="remove" 
                    :index="index"
                ></slot>
            </div>
        `,
        props: ['items', 'getNextPage', 'loadAtPage', 'layout', 'layoutMode', 'mobileBreakpoint', 'skipInitialLoad', 'backfillEnabled', 'backfillDelayMs', 'backfillMaxCalls'],
        emits: ['backfill:start', 'backfill:tick', 'backfill:stop', 'backfill:retry-start', 'backfill:retry-tick', 'backfill:retry-stop'],
        setup(props: { items: any[] }, { expose }: any) {
            expose({
                isLoading: mockIsLoading,
                init: mockInit,
                refreshLayout: mockRefreshLayout,
                cancelLoad: mockCancelLoad,
                destroy: mockDestroy,
                remove: mockRemove,
                loadPage: mockLoadPage,
                loadNext: mockLoadNext,
                reset: mockReset,
            });
            return {};
        },
    },
    MasonryItem: {
        name: 'MasonryItem',
        template: `
            <div 
                class="masonry-item" 
                :data-key="item.key"
                @mouseenter="$emit('mouseenter', $event)" 
                @mouseleave="$emit('mouseleave', $event)"
                @mousedown="$emit('mousedown', $event)"
                @auxclick="$emit('auxclick', $event)"
            >
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
        emits: ['mouseenter', 'mouseleave', 'mousedown', 'auxclick', 'preload:success'],
    },
}));

// Mock FileViewer
vi.mock('./FileViewer.vue', () => ({
    default: {
        name: 'FileViewer',
        template: '<div class="file-viewer-mock"></div>',
        props: ['containerRef', 'masonryContainerRef', 'items', 'hasMore', 'isLoading', 'onLoadMore', 'onReaction', 'removeFromMasonry', 'restoreToMasonry', 'tabId', 'masonryInstance'],
        emits: ['close'],
        methods: {
            openFromClick: vi.fn(),
            close: vi.fn(),
        },
        expose: ['openFromClick', 'close'],
    },
}));

// Mock BrowseStatusBar
vi.mock('./BrowseStatusBar.vue', () => ({
    default: {
        name: 'BrowseStatusBar',
        template: '<div class="browse-status-bar-mock"></div>',
        props: ['items', 'displayPage', 'nextCursor', 'isLoading', 'backfill', 'queuedReactionsCount', 'visible'],
    },
}));

// Mock FileReactions
vi.mock('./FileReactions.vue', () => ({
    default: {
        name: 'FileReactions',
        template: '<div class="file-reactions-mock"></div>',
        props: ['fileId', 'previewedCount', 'viewedCount', 'currentIndex', 'totalItems', 'variant', 'removeItem'],
        emits: ['reaction'],
    },
}));

// Mock UI components
vi.mock('./ui/button', () => ({
    Button: {
        name: 'Button',
        template: '<button><slot></slot></button>',
        props: ['variant', 'size', 'disabled', 'color'],
    },
}));

vi.mock('./ui/Pill.vue', () => ({
    default: {
        name: 'Pill',
        template: '<span class="pill-mock"><span class="pill-label">{{ label }}</span><span class="pill-value">{{ value }}</span></span>',
        props: ['label', 'value', 'variant', 'size', 'reversed', 'dismissible'],
    },
}));

vi.mock('./ui/select', () => ({
    Select: {
        name: 'Select',
        template: '<div class="select-mock"><slot></slot></div>',
        props: ['modelValue', 'disabled'],
        emits: ['update:modelValue'],
    },
    SelectContent: {
        name: 'SelectContent',
        template: '<div class="select-content-mock"><slot></slot></div>',
    },
    SelectItem: {
        name: 'SelectItem',
        template: '<div class="select-item-mock"><slot></slot></div>',
        props: ['value'],
    },
    SelectTrigger: {
        name: 'SelectTrigger',
        template: '<div class="select-trigger-mock"><slot></slot></div>',
        props: ['class'],
    },
    SelectValue: {
        name: 'SelectValue',
        template: '<div class="select-value-mock"><slot></slot></div>',
        props: ['placeholder'],
    },
}));

vi.mock('./ui/dialog', () => ({
    Dialog: {
        name: 'Dialog',
        template: '<div class="dialog-mock"><slot></slot></div>',
        props: ['modelValue'],
        emits: ['update:modelValue'],
    },
    DialogContent: {
        name: 'DialogContent',
        template: '<div class="dialog-content-mock"><slot></slot></div>',
        props: ['class'],
    },
    DialogDescription: {
        name: 'DialogDescription',
        template: '<div class="dialog-description-mock"><slot></slot></div>',
        props: ['class'],
    },
    DialogFooter: {
        name: 'DialogFooter',
        template: '<div class="dialog-footer-mock"><slot></slot></div>',
    },
    DialogHeader: {
        name: 'DialogHeader',
        template: '<div class="dialog-header-mock"><slot></slot></div>',
    },
    DialogTitle: {
        name: 'DialogTitle',
        template: '<div class="dialog-title-mock"><slot></slot></div>',
        props: ['class'],
    },
    DialogClose: {
        name: 'DialogClose',
        template: '<div class="dialog-close-mock"><slot></slot></div>',
        props: ['asChild'],
    },
}));

// Mock icons
vi.mock('lucide-vue-next', () => ({
    Loader2: { name: 'Loader2', template: '<div class="loader-icon"></div>', props: ['size', 'class'] },
    AlertTriangle: { name: 'AlertTriangle', template: '<div class="alert-icon"></div>', props: ['size'] },
    Info: { name: 'Info', template: '<div class="info-icon"></div>', props: ['size'] },
    Copy: { name: 'Copy', template: '<div class="copy-icon"></div>', props: ['size', 'class'] },
}));

// Mock composables
vi.mock('@/composables/useBackfill', () => ({
    useBackfill: () => ({
        backfill: { value: { isRunning: false, calls: 0 } },
        onBackfillStart: vi.fn(),
        onBackfillTick: vi.fn(),
        onBackfillStop: vi.fn(),
        onBackfillRetryStart: vi.fn(),
        onBackfillRetryTick: vi.fn(),
        onBackfillRetryStop: vi.fn(),
    }),
}));

vi.mock('@/composables/useBrowseService', () => ({
    useBrowseService: () => ({
        isApplyingService: ref(false),
        getNextPage: vi.fn().mockResolvedValue({ items: [], nextPage: null }),
        applyService: vi.fn().mockResolvedValue(undefined),
    }),
}));

vi.mock('@/composables/useReactionQueue', () => ({
    useReactionQueue: () => ({
        queuedReactions: ref([]),
        queueReaction: vi.fn(),
        cancelReaction: vi.fn(),
    }),
}));

vi.mock('@/utils/reactions', () => ({
    createReactionCallback: vi.fn(() => vi.fn()),
}));

vi.mock('@/actions/App/Http/Controllers/FilesController', () => ({
    incrementPreview: {
        url: (id: number) => `/api/files/${id}/increment-preview`,
    },
}));

beforeEach(() => {
    vi.clearAllMocks();
    mockIsLoading.value = false;
    mockCancelLoad.mockClear();
    mockDestroy.mockClear();
    mockInit.mockClear();
    mockRemove.mockClear();
    mockRefreshLayout.mockClear();
    mockLoadPage.mockClear();
    mockLoadNext.mockClear();
    mockReset.mockClear();

    // Default mock for axios
    mockAxios.get.mockResolvedValue({ data: { items: [], nextPage: null } });
    mockAxios.post.mockResolvedValue({ data: { previewed_count: 0, auto_disliked: false } });
    mockAxios.put.mockResolvedValue({ data: {} });
    mockAxios.delete.mockResolvedValue({ data: {} });
    mockAxios.patch.mockResolvedValue({ data: {} });
});

describe('BrowseTabContent - Container Badges', () => {
    const createMockTab = (overrides = {}) => ({
        id: 1,
        label: 'Test Tab',
        queryParams: { service: 'test-service', page: 1, next: null },
        fileIds: [],
        itemsData: [],
        position: 0,
        isActive: true,
        ...overrides,
    });

    const createMockItem = (id: number, containers: Array<{ type: string; id?: number; source?: string; source_id?: string; referrer?: string }> = []): MasonryItem => ({
        id,
        width: 500,
        height: 500,
        page: 1,
        key: `1-${id}`,
        index: id - 1,
        src: `https://example.com/image${id}.jpg`,
        originalUrl: `https://example.com/original${id}.jpg`,
        thumbnail: `https://example.com/thumb${id}.jpg`,
        type: 'image',
        notFound: false,
        previewed_count: 0,
        seen_count: 0,
        auto_disliked: false,
        containers,
    } as MasonryItem);

    it('displays container badges with type and count when item has containers', async () => {
        // Item 1 has container id=1 (gallery) and id=2 (album)
        // Item 2 has container id=1 (gallery) - same as item1's first container
        // Item 3 has container id=2 (album) - same as item1's second container
        const item1 = createMockItem(1, [
            { type: 'gallery', id: 1 },
            { type: 'album', id: 2 },
        ]);
        const item2 = createMockItem(2, [
            { type: 'gallery', id: 1 }, // Same container ID as item1's gallery
            { type: 'collection', id: 3 },
        ]);
        const item3 = createMockItem(3, [
            { type: 'album', id: 2 }, // Same container ID as item1's album
        ]);

        const tab = createMockTab({
            itemsData: [item1, item2, item3],
            fileIds: [1, 2, 3],
        });

        const wrapper = mount(BrowseTabContent, {
            props: {
                tab,
                availableServices: [{ key: 'test-service', label: 'Test Service' }],
                onReaction: vi.fn(),
                updateActiveTab: vi.fn(),
                loadTabItems: vi.fn().mockResolvedValue([item1, item2, item3]),
            },
        });

        await flushPromises();
        await nextTick();

        // Find the first masonry item
        const masonryItems = wrapper.findAll('.masonry-item');
        expect(masonryItems.length).toBeGreaterThan(0);

        // Hover over the first item
        const firstItem = masonryItems[0];
        await firstItem.trigger('mouseenter');
        await nextTick();

        // Check that container badges are displayed
        const badgeContainers = wrapper.findAll('.absolute.top-2.left-2 .pill-mock');
        expect(badgeContainers.length).toBeGreaterThan(0);

        // Verify badge content - should show type and count in separate spans
        const badgeTexts = badgeContainers.map(badge => badge.text());

        // Item 1 has container id=1 (gallery) - shared with item2, so count=2
        // Item 1 has container id=2 (album) - shared with item3, so count=2
        expect(badgeTexts.some(text => text.includes('gallery') && text.includes('2'))).toBe(true);
        expect(badgeTexts.some(text => text.includes('album') && text.includes('2'))).toBe(true);
    });

    it('does not display container badges when item has no containers', async () => {
        const item1 = createMockItem(1, []);
        const item2 = createMockItem(2, [{ type: 'gallery', id: 1 }]);

        const tab = createMockTab({
            itemsData: [item1, item2],
            fileIds: [1, 2],
        });

        const wrapper = mount(BrowseTabContent, {
            props: {
                tab,
                availableServices: [{ key: 'test-service', label: 'Test Service' }],
                onReaction: vi.fn(),
                updateActiveTab: vi.fn(),
                loadTabItems: vi.fn().mockResolvedValue([item1, item2]),
            },
        });

        await flushPromises();
        await nextTick();

        // Find masonry items
        const masonryItems = wrapper.findAll('.masonry-item');
        expect(masonryItems.length).toBeGreaterThan(0);

        // Hover over the first item (which has no containers)
        const firstItem = masonryItems[0];
        await firstItem.trigger('mouseenter');
        await nextTick();

        // Check that no container badges are displayed for item without containers
        const badgeContainers = wrapper.findAll('.absolute.top-2.left-2 .pill-mock');
        // Should be 0 or badges should not contain container info for item 1
        // Since we're hovering item 1, there should be no badges
        expect(badgeContainers.length).toBe(0);
    });

    it('correctly counts items with the same container ID', async () => {
        // Container id=1 appears in item1 and item2
        // Container id=2 appears in item1 and item3
        // Container id=3 appears only in item2
        const item1 = createMockItem(1, [
            { type: 'gallery', id: 1 },
            { type: 'album', id: 2 },
        ]);
        const item2 = createMockItem(2, [
            { type: 'gallery', id: 1 }, // Same container ID as item1
            { type: 'collection', id: 3 },
        ]);
        const item3 = createMockItem(3, [
            { type: 'album', id: 2 }, // Same container ID as item1
        ]);

        const tab = createMockTab({
            itemsData: [item1, item2, item3],
            fileIds: [1, 2, 3],
        });

        const wrapper = mount(BrowseTabContent, {
            props: {
                tab,
                availableServices: [{ key: 'test-service', label: 'Test Service' }],
                onReaction: vi.fn(),
                updateActiveTab: vi.fn(),
                loadTabItems: vi.fn().mockResolvedValue([item1, item2, item3]),
            },
        });

        await flushPromises();
        await nextTick();

        // Get the component instance
        const vm = wrapper.vm as any;

        // Check getItemCountForContainerId function
        // Container id=1 appears in item1 and item2 = 2 items
        expect(vm.getItemCountForContainerId(1)).toBe(2);

        // Container id=2 appears in item1 and item3 = 2 items
        expect(vm.getItemCountForContainerId(2)).toBe(2);

        // Container id=3 appears only in item2 = 1 item
        expect(vm.getItemCountForContainerId(3)).toBe(1);
    });

    it('correctly gets containers for a specific item', async () => {
        const item1 = createMockItem(1, [
            { type: 'gallery', id: 1 },
            { type: 'gallery', id: 2 },
            { type: 'album', id: 3 },
        ]);

        const tab = createMockTab({
            itemsData: [item1],
            fileIds: [1],
        });

        const wrapper = mount(BrowseTabContent, {
            props: {
                tab,
                availableServices: [{ key: 'test-service', label: 'Test Service' }],
                onReaction: vi.fn(),
                updateActiveTab: vi.fn(),
                loadTabItems: vi.fn().mockResolvedValue([item1]),
            },
        });

        await flushPromises();
        await nextTick();

        // Get the component instance
        const vm = wrapper.vm as any;

        // Check getContainersForItem function
        const containers = vm.getContainersForItem(item1);

        // Item 1 has 3 containers
        expect(containers.length).toBe(3);
        expect(containers[0]).toEqual({ id: 1, type: 'gallery' });
        expect(containers[1]).toEqual({ id: 2, type: 'gallery' });
        expect(containers[2]).toEqual({ id: 3, type: 'album' });
    });

    it('updates container counts when items are added or removed', async () => {
        // Both items share the same container ID
        const item1 = createMockItem(1, [{ type: 'gallery', id: 1 }]);
        const item2 = createMockItem(2, [{ type: 'gallery', id: 1 }]); // Same container ID

        const tab = createMockTab({
            itemsData: [item1],
            fileIds: [1],
        });

        const wrapper = mount(BrowseTabContent, {
            props: {
                tab,
                availableServices: [{ key: 'test-service', label: 'Test Service' }],
                onReaction: vi.fn(),
                updateActiveTab: vi.fn(),
                loadTabItems: vi.fn().mockResolvedValue([item1]),
            },
        });

        await flushPromises();
        await nextTick();

        const vm = wrapper.vm as any;

        // Initial count: container id=1 appears in 1 item
        expect(vm.getItemCountForContainerId(1)).toBe(1);

        // Add item2 (which also has container id=1)
        vm.items.push(item2);
        await nextTick();

        // Count should now be 2 (both items have container id=1)
        expect(vm.getItemCountForContainerId(1)).toBe(2);

        // Remove item1
        const item1Index = vm.items.findIndex((i: MasonryItem) => i.id === 1);
        if (item1Index !== -1) {
            vm.items.splice(item1Index, 1);
            await nextTick();

            // Count should be back to 1 (only item2 has container id=1)
            expect(vm.getItemCountForContainerId(1)).toBe(1);
        }
    });

    it('only shows container badges when image is loaded and item is hovered', async () => {
        const item1 = createMockItem(1, [{ type: 'gallery', id: 1 }]);

        const tab = createMockTab({
            itemsData: [item1],
            fileIds: [1],
        });

        const wrapper = mount(BrowseTabContent, {
            props: {
                tab,
                availableServices: [{ key: 'test-service', label: 'Test Service' }],
                onReaction: vi.fn(),
                updateActiveTab: vi.fn(),
                loadTabItems: vi.fn().mockResolvedValue([item1]),
            },
        });

        await flushPromises();
        await nextTick();

        // Before hover, badges should not be visible
        let badgeContainers = wrapper.findAll('.absolute.top-2.left-2 .inline-flex');
        expect(badgeContainers.length).toBe(0);

        // Hover over item
        const masonryItems = wrapper.findAll('.masonry-item');
        if (masonryItems.length > 0) {
            await masonryItems[0].trigger('mouseenter');
            await nextTick();

            // After hover, badges should be visible (imageLoaded is true in mock)
            badgeContainers = wrapper.findAll('.absolute.top-2.left-2 .pill-mock');
            expect(badgeContainers.length).toBeGreaterThan(0);

            // Stop hovering
            await masonryItems[0].trigger('mouseleave');
            await nextTick();

            // Badges should no longer be visible
            badgeContainers = wrapper.findAll('.absolute.top-2.left-2 .pill-mock');
            expect(badgeContainers.length).toBe(0);
        }
    });
});

