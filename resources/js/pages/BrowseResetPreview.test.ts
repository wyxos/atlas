import { describe, expect, it, vi, beforeEach } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { ref } from 'vue';
import Browse from './Browse.vue';
import {
    createMockTabConfig,
    createTestRouter,
    setupAxiosMocks,
    setupBrowseTestMocks,
    waitForStable,
    waitForTabContent,
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

const mockClearAutoDislikeCountdowns = vi.fn();

global.fetch = vi.fn();

vi.mock('axios', () => ({
    default: mockAxios,
}));

Object.defineProperty(window, 'axios', {
    value: mockAxios,
    writable: true,
});

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
        props: ['items', 'getContent', 'getPage', 'page', 'layout', 'layoutMode', 'init', 'mode', 'restoredPages', 'pageSize', 'gapX', 'gapY'],
        emits: ['update:items', 'preloaded', 'failures'],
        setup() {
            return {
                init: mockInit,
                initialize: mockInit,
                cancelLoad: mockCancelLoad,
                destroy: mockDestroy,
                remove: mockRemove,
                removeMany: mockRemoveMany,
                restore: mockRestore,
                restoreMany: mockRestoreMany,
                loadPage: vi.fn(),
                loadNext: vi.fn(),
                reset: vi.fn(),
                isLoading: mockIsLoading.value,
                hasReachedEnd: false,
            };
        },
    },
    MasonryItem: {
        name: 'MasonryItem',
        template: `
            <div>
                <slot :item="item" :remove="remove"></slot>
            </div>
        `,
        props: ['item', 'remove'],
        emits: ['mouseenter', 'mouseleave', 'preload:success'],
    },
}));

vi.mock('@/composables/usePreviewBatch', () => ({
    usePreviewBatch: () => ({
        queuePreviewIncrement: mockQueuePreviewIncrement,
    }),
}));

vi.mock('@/composables/useAutoDislikeQueue', () => ({
    useAutoDislikeQueue: () => ({
        startAutoDislikeCountdown: vi.fn(),
        cancelAutoDislikeCountdown: vi.fn(),
        getCountdownRemainingTime: vi.fn(() => 0),
        getCountdownProgress: vi.fn(() => 0),
        hasActiveCountdown: vi.fn(() => false),
        formatCountdown: vi.fn(() => '00:00'),
        freezeAll: vi.fn(),
        unfreezeAll: vi.fn(),
        freezeAutoDislikeOnly: vi.fn(),
        unfreezeAutoDislikeOnly: vi.fn(),
        isFrozen: ref(false),
        clearAutoDislikeCountdowns: mockClearAutoDislikeCountdowns,
    }),
}));

vi.mock('@/components/ui/dropdown-menu', () => ({
    DropdownMenu: {
        name: 'DropdownMenu',
        template: '<div class="dropdown-menu-mock"><slot></slot></div>',
    },
    DropdownMenuTrigger: {
        name: 'DropdownMenuTrigger',
        template: '<div class="dropdown-menu-trigger-mock"><slot></slot></div>',
        props: ['asChild'],
    },
    DropdownMenuContent: {
        name: 'DropdownMenuContent',
        template: '<div class="dropdown-menu-content-mock"><slot></slot></div>',
        props: ['align', 'class'],
    },
    DropdownMenuLabel: {
        name: 'DropdownMenuLabel',
        template: '<div class="dropdown-menu-label-mock"><slot></slot></div>',
        props: ['class'],
    },
    DropdownMenuSeparator: {
        name: 'DropdownMenuSeparator',
        template: '<div class="dropdown-menu-separator-mock"></div>',
        props: ['class'],
    },
    DropdownMenuItem: {
        name: 'DropdownMenuItem',
        template: '<button v-bind="$attrs" :disabled="disabled" @click="$emit(\'select\')"><slot></slot></button>',
        props: ['disabled', 'class'],
        emits: ['select'],
    },
}));

beforeEach(() => {
    setupBrowseTestMocks(mocks);
    mockClearAutoDislikeCountdowns.mockClear();
});

describe('Browse - Reset Previewed', () => {
    it('resets preview counts only for unreacted loaded tab items and clears queued auto dislikes', async () => {
        const items = [
            {
                id: 1,
                width: 300,
                height: 400,
                src: 'test1.jpg',
                type: 'image',
                page: 1,
                index: 0,
                notFound: false,
                previewed_count: 3,
                will_auto_dislike: true,
                reaction: { type: 'like' },
            },
            {
                id: 2,
                width: 320,
                height: 420,
                src: 'test2.jpg',
                type: 'image',
                page: 1,
                index: 1,
                notFound: false,
                previewed_count: 1,
                will_auto_dislike: false,
            },
        ];
        const tabConfig = createMockTabConfig(1, {
            items,
        });

        setupAxiosMocks(mocks, tabConfig);
        mocks.mockAxios.post.mockImplementation((url: string) => {
            if (url === '/api/files/preview/reset/batch') {
                return Promise.resolve({
                    data: {
                        message: 'Preview counts reset.',
                        results: [
                            { id: 2, previewed_count: 0 },
                        ],
                    },
                });
            }

            return Promise.resolve({ data: {} });
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

        await wrapper.get('[data-test="loaded-items-reset-previewed"]').trigger('click');
        await flushPromises();
        await wrapper.vm.$nextTick();

        expect(mocks.mockAxios.post).toHaveBeenCalledWith('/api/files/preview/reset/batch', {
            file_ids: [2],
        });
        expect(mockClearAutoDislikeCountdowns).toHaveBeenCalledTimes(1);

        const updatedItems = tabContentVm.items as Array<{ id: number; previewed_count: number; will_auto_dislike: boolean }>;
        expect(updatedItems[0]?.previewed_count).toBe(3);
        expect(updatedItems[0]?.will_auto_dislike).toBe(true);
        expect(updatedItems[1]?.previewed_count).toBe(0);
        expect(updatedItems[1]?.will_auto_dislike).toBe(false);
    });
});
