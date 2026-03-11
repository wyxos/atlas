/* eslint-disable @typescript-eslint/no-explicit-any */
import { vi, beforeEach } from 'vitest';
import { mount as baseMount, flushPromises } from '@vue/test-utils';
import { cloneVNode, defineComponent, h, nextTick, ref } from 'vue';

const mount = baseMount;

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

// Mock composables
vi.mock('@/composables/useContainerBadges', async () => {
    const { ref, computed } = await import('vue');
    return {
        useContainerBadges: vi.fn((itemsRef: any) => {
            const hoveredContainerId = ref<number | null>(null);
            // TabContent uses the debounced "active" hover id for visual effects.
            // For unit tests we can mirror it to the immediate hovered state.
            const activeHoveredContainerId = hoveredContainerId;
            const setHoveredContainerId = vi.fn((id: number | null) => {
                hoveredContainerId.value = id;
            });
            return {
                hoveredContainerId,
                activeHoveredContainerId,
                setHoveredContainerId,
                getContainersForItem: (item: any) => {
                    const containers = (item as any).containers || [];
                    return containers.filter((c: any) => c?.id && c?.type);
                },
                getItemCountForContainerId: (containerId: number) => {
                    // Count items with this container id
                    let count = 0;
                    itemsRef.value.forEach((item: any) => {
                        const containers = (item as any).containers || [];
                        if (containers.some((c: any) => c?.id === containerId)) {
                            count++;
                        }
                    });
                    return count;
                },
                getVariantForContainerType: () => 'primary',
                getBorderColorClassForVariant: () => 'border-smart-blue-500',
                isSiblingItem: (item: any, hoveredId: number | null) => {
                    if (hoveredId === null) return false;
                    const containers = (item as any).containers || [];
                    return containers.some((c: any) => c?.id === hoveredId);
                },
                getHoveredContainerVariant: () => null,
                getMasonryItemClasses: computed(() => () => 'border-2 border-transparent opacity-100'),
            };
        }),
    };
});

const mockBatchReactToSiblings = vi.fn();
const mockHandlePillClick = vi.fn();
const mockHandlePillAuxClick = vi.fn();
let capturedOpenContainerTab: ((container: any) => void) | null = null;
vi.mock('@/composables/useContainerPillInteractions', () => ({
    useContainerPillInteractions: vi.fn((options: { onOpenContainerTab?: (container: any) => void }) => {
        capturedOpenContainerTab = options.onOpenContainerTab ?? null;
        return {
            getContainersForItem: vi.fn((item: any) => (item as any).containers || []),
            getSiblingItems: vi.fn((_containerId: number) => []),
            getContainerUrl: vi.fn((containerId: number) => `https://example.com/container/${containerId}`),
            batchReactToSiblings: mockBatchReactToSiblings,
            handlePillClick: mockHandlePillClick,
            handlePillAuxClick: mockHandlePillAuxClick,
        };
    }),
}));

vi.mock('@/composables/usePromptData', async () => {
    const { ref, computed } = await import('vue');
    return {
        usePromptData: vi.fn(() => ({
            promptDataLoading: ref(new Map()),
            promptDataCache: ref(new Map()),
            promptDialogOpen: ref(false),
            promptDialogItemId: ref(null),
            loadPromptData: vi.fn(),
            getPromptData: vi.fn(() => null),
            currentPromptItem: computed(() => null),
            currentPromptData: computed(() => null),
            openPromptDialog: vi.fn(),
            closePromptDialog: vi.fn(),
            copyPromptToClipboard: vi.fn(),
        })),
    };
});

vi.mock('@/utils/masonryInteractions', () => ({
    createMasonryInteractions: vi.fn(() => ({
        handleAltClickReaction: vi.fn(),
        handleMasonryItemMouseDown: vi.fn(),
        handleMasonryItemAuxClick: vi.fn(),
        openOriginalUrl: vi.fn(),
    })),
}));

vi.mock('@/composables/useItemPreview', async () => {
    const { ref } = await import('vue');
    const mockIncrementPreviewCount = vi.fn();
    const mockClearPreviewedItems = vi.fn();
    return {
        useItemPreview: vi.fn(() => ({
            previewedItems: ref(new Set()),
            incrementPreviewCount: mockIncrementPreviewCount,
            clearPreviewedItems: mockClearPreviewedItems,
        })),
        __test: {
            mockIncrementPreviewCount,
            mockClearPreviewedItems,
        },
    };
});

const mockClearAutoDislikeCountdowns = vi.fn();
const mockStartAutoDislikeCountdown = vi.fn();
const mockHasActiveCountdown = vi.fn(() => false);
vi.mock('@/composables/useAutoDislikeQueue', async () => {
    const { ref } = await import('vue');
    return {
        useAutoDislikeQueue: vi.fn(() => ({
            startAutoDislikeCountdown: mockStartAutoDislikeCountdown,
            cancelAutoDislikeCountdown: vi.fn(),
            getCountdownRemainingTime: vi.fn(() => 0),
            getCountdownProgress: vi.fn(() => 0),
            hasActiveCountdown: mockHasActiveCountdown,
            formatCountdown: vi.fn(() => '00:00'),
            freezeAll: vi.fn(),
            unfreezeAll: vi.fn(),
            freezeAutoDislikeOnly: vi.fn(),
            unfreezeAutoDislikeOnly: vi.fn(),
            isFrozen: ref(false),
            clearAutoDislikeCountdowns: mockClearAutoDislikeCountdowns,
        })),
    };
});

// Mock @wyxos/vibe
const mockIsLoading = ref(false);
const mockCancelLoad = vi.fn();
const mockDestroy = vi.fn();
const mockInit = vi.fn();
const mockRemove = vi.fn();
const mockRemoveMany = vi.fn();
const mockLoadPage = vi.fn();
const mockLoadNext = vi.fn();
const mockReset = vi.fn();
const mockInitialize = vi.fn();

const vibeShouldEmitPreloaded = true;

vi.mock('@wyxos/vibe', () => {
    const Masonry = defineComponent({
        name: 'MasonryGrid',
        props: ['items', 'getContent', 'getPage', 'page', 'layout', 'layoutMode', 'init', 'mode', 'restoredPages', 'pageSize', 'gapX', 'gapY'],
        emits: ['update:items', 'preloaded', 'failures', 'removed'],
        setup(props: any, { expose, emit, slots }: any) {
            let currentPage: number | string | null = null;
            let nextPage: number | string | null = null;
            let hasReachedEnd = false;
            let paginationHistory: Array<number | string> = [];

            const remove = (itemToRemove: any) => {
                mockRemove(itemToRemove);
                const currentItems = props.items ?? [];
                const nextItems = currentItems.filter((item: any) => item?.id !== itemToRemove?.id);
                if (nextItems.length !== currentItems.length) {
                    emit('update:items', nextItems);
                }
            };

            const initialize = (itemsToRestore: any[], page: number | string, next: number | string | null) => {
                mockInitialize(itemsToRestore, page, next);
                const nextItems = [...itemsToRestore];
                emit('update:items', nextItems);
                currentPage = page;
                nextPage = next ?? null;
                paginationHistory = nextPage === null ? [] : [nextPage];
                hasReachedEnd = nextPage === null;
            };

            const reset = () => {
                mockReset();
                emit('update:items', []);
                currentPage = null;
                nextPage = null;
                paginationHistory = [];
                hasReachedEnd = false;
            };

            const loadNextPage = async () => {
                mockLoadNext();
                const getContent = props.getContent ?? props.getPage;
                if (!getContent || nextPage === null || nextPage === undefined) {
                    return;
                }
                const pageToLoad = nextPage;
                currentPage = pageToLoad;
                const result = await getContent(pageToLoad);
                const newItems = result?.items ?? [];
                const nextItems = [...(props.items ?? []), ...newItems];
                emit('update:items', nextItems);
                nextPage = result?.nextPage ?? null;
                paginationHistory = nextPage === null ? [] : [nextPage];
                hasReachedEnd = nextPage === null;
                return result;
            };

            const cancel = () => {
                mockCancelLoad();
            };

            expose({
                init: mockInit,
                initialize,
                cancelLoad: mockCancelLoad,
                cancel,
                destroy: mockDestroy,
                remove,
                removeMany: mockRemoveMany,
                loadNext: loadNextPage,
                loadNextPage,
                reset,
                get isLoading() { return mockIsLoading.value; },
                set isLoading(value: boolean) { mockIsLoading.value = value; },
                get currentPage() { return currentPage; },
                set currentPage(value: number | string | null) { currentPage = value; },
                get nextPage() { return nextPage; },
                set nextPage(value: number | string | null) { nextPage = value; },
                get paginationHistory() { return paginationHistory; },
                set paginationHistory(value: Array<number | string>) { paginationHistory = value; },
                get hasReachedEnd() { return hasReachedEnd; },
                set hasReachedEnd(value: boolean) { hasReachedEnd = value; },
            });

            // Simulate Vibe's debounced batch preloaded emit for items already "in view".
            // TabContent uses this to mark items as preloaded (for hover overlays).
            Promise.resolve().then(() => {
                if (vibeShouldEmitPreloaded && (props.items ?? []).length > 0) {
                    emit('preloaded', [...props.items]);
                }
            });

            return () => {
                const definition = slots.default?.()?.[0];
                const children = (props.items ?? []).map((item: any, index: number) => {
                    if (!definition) {
                        return null;
                    }
                    return cloneVNode(definition, {
                        key: item?.id ?? index,
                        item,
                        remove,
                        index,
                    });
                });

                return h('div', { class: 'masonry-mock', 'data-test': 'masonry-component' }, children);
            };
        },
    });

    const MasonryItem = defineComponent({
        name: 'MasonryItem',
        props: ['item', 'remove'],
        emits: ['preloaded', 'failed'],
        setup(props: any, { slots }: any) {
            return () => {
                const overlayNodes = slots.overlay?.({ item: props.item, remove: props.remove }) ?? [];
                const node = overlayNodes[0];

                if (!node) {
                    return h('article', { 'data-testid': 'item-card' });
                }

                return cloneVNode(node, {
                    class: [
                        (node.props as any)?.class,
                        'item-card',
                    ],
                    'data-testid': 'item-card',
                });
            };
        },
    });

    return { Masonry, MasonryItem };
});

// Mock FileViewer
import './TabContent.test.setup.ui-mocks';
vi.mock('@/composables/useBrowseService', async () => {
    const { ref } = await import('vue');
    return {
        useBrowseService: () => ({
            availableServices: ref([]),
            availableSources: ref([]),
            fetchServices: vi.fn(),
            fetchSources: vi.fn(),
            getPage: vi.fn().mockResolvedValue({ items: [], nextPage: null }),
            applyService: vi.fn().mockResolvedValue(undefined),
        }),
    };
});


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
    capturedOpenContainerTab = null;
    mockClearAutoDislikeCountdowns.mockClear();
    mockIsLoading.value = false;
    mockCancelLoad.mockClear();
    mockDestroy.mockClear();
    mockInit.mockClear();
    mockRemove.mockClear();
    mockRemoveMany.mockClear();
    mockLoadPage.mockClear();
    mockLoadNext.mockClear();
    mockReset.mockClear();
    mockInitialize.mockClear();

    // Default mock for axios
    mockAxios.get.mockResolvedValue({ data: { items: [], nextPage: null } });
    mockAxios.post.mockResolvedValue({ data: { previewed_count: 0 } });
    mockAxios.put.mockResolvedValue({ data: {} });
    mockAxios.delete.mockResolvedValue({ data: {} });
    mockAxios.patch.mockResolvedValue({ data: {} });
});


export {
    mount,
    flushPromises,
    nextTick,
    ref,
    mockAxios,
    mockBatchReactToSiblings,
    mockHandlePillClick,
    mockHandlePillAuxClick,
    mockClearAutoDislikeCountdowns,
    mockStartAutoDislikeCountdown,
    mockHasActiveCountdown,
    mockIsLoading,
    mockCancelLoad,
    mockDestroy,
    mockInit,
    mockRemove,
    mockRemoveMany,
    mockLoadPage,
    mockLoadNext,
    mockReset,
    mockInitialize,
    capturedOpenContainerTab,
};

