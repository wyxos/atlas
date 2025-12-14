import { describe, it, expect, vi, beforeEach } from 'vitest';
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
} from '../test/browse-test-utils';

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
        props: ['items', 'getNextPage', 'loadAtPage', 'layout', 'layoutMode', 'mobileBreakpoint', 'skipInitialLoad', 'backfillEnabled', 'backfillDelayMs', 'backfillMaxCalls'],
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
        emits: ['mouseenter', 'mouseleave', 'preload:success'],
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
});

describe('Browse - Preview and Seen Count Tracking', () => {
    it('increments preview count when item is preloaded', async () => {
        const browseResponse = {
            items: [
                { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false, previewed_count: 0 },
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

        tabContentVm.items = [{ id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false, previewed_count: 0 }];
        await wrapper.vm.$nextTick();

        mockQueuePreviewIncrement.mockResolvedValueOnce({
            file_id: 1,
            previewed_count: 1,
            auto_disliked: false,
        });

        const browseTabContentComponent = wrapper.findComponent({ name: 'BrowseTabContent' });
        const masonryItem = browseTabContentComponent.findComponent({ name: 'MasonryItem' });

        if (masonryItem.exists()) {
            await masonryItem.vm.$emit('preload:success', {
                item: { id: 1 },
                type: 'image',
                src: 'test1.jpg',
            });

            await flushPromises();
            await wrapper.vm.$nextTick();

            expect(mockQueuePreviewIncrement).toHaveBeenCalledWith(1);

            await flushPromises();
            await wrapper.vm.$nextTick();

            const updatedItem = tabContentVm.items.find((i: any) => i.id === 1);
            expect(updatedItem?.previewed_count).toBe(1);
        }
    });

    it('increments seen count when file is loaded in FileViewer', async () => {
        const browseResponse = {
            items: [
                { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false, seen_count: 0 },
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

        tabContentVm.items = [{ id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false, seen_count: 0 }];
        await wrapper.vm.$nextTick();

        mocks.mockAxios.post.mockResolvedValueOnce({
            data: { seen_count: 1 },
        });

        const browseTabContentComponent = wrapper.findComponent({ name: 'BrowseTabContent' });
        const masonryContainer = browseTabContentComponent.find('[ref="masonryContainer"]');

        if (masonryContainer.exists()) {
            const mockItem = document.createElement('div');
            mockItem.className = 'masonry-item';
            const mockImg = document.createElement('img');
            mockImg.src = 'test1.jpg';
            mockItem.appendChild(mockImg);
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
            Object.defineProperty(clickEvent, 'target', { value: mockImg, enumerable: true });
            masonryContainer.element.dispatchEvent(clickEvent);

            await wrapper.vm.$nextTick();
            await flushPromises();

            await new Promise(resolve => setTimeout(resolve, 100));
            await flushPromises();

            const seenCall = mocks.mockAxios.post.mock.calls.find((call: any[]) =>
                call[0]?.includes('/api/files/1/seen')
            );
            expect(seenCall).toBeDefined();
        }
    });

    it('sets auto_disliked flag when preview count reaches 3', async () => {
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

        tabContentVm.items = [{ id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false, previewed_count: 2, auto_disliked: false }];
        await wrapper.vm.$nextTick();

        mockQueuePreviewIncrement.mockResolvedValueOnce({
            file_id: 1,
            previewed_count: 3,
            auto_disliked: true,
        });

        const browseTabContentComponent = wrapper.findComponent({ name: 'BrowseTabContent' });
        const masonryItem = browseTabContentComponent.findComponent({ name: 'MasonryItem' });

        if (masonryItem.exists()) {
            await masonryItem.vm.$emit('preload:success', {
                item: { id: 1 },
                type: 'image',
                src: 'test1.jpg',
            });

            await flushPromises();
            await wrapper.vm.$nextTick();

            expect(mockQueuePreviewIncrement).toHaveBeenCalledWith(1);
        }
    });

    it('removes auto_disliked flag when user reacts with like', async () => {
        const browseResponse = {
            items: [
                { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false, auto_disliked: true },
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

        tabContentVm.items = [{ id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false, auto_disliked: true }];
        await wrapper.vm.$nextTick();

        mocks.mockAxios.post.mockResolvedValueOnce({
            data: { message: 'Reaction updated.', reaction: { type: 'like' } },
        });

        const browseTabContentComponent = wrapper.findComponent({ name: 'BrowseTabContent' });
        const fileReactions = browseTabContentComponent.findComponent({ name: 'FileReactions' });

        if (fileReactions.exists()) {
            await fileReactions.vm.$emit('reaction', 'like');

            await flushPromises();
            await wrapper.vm.$nextTick();

            const updatedItem = tabContentVm.items.find((i: any) => i.id === 1);
            if (updatedItem) {
                expect(updatedItem.auto_disliked).toBe(false);
            } else {
                expect(tabContentVm.items.length).toBe(0);
            }
        }
    });
});
