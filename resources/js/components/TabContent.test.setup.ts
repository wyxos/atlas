/* eslint-disable @typescript-eslint/no-explicit-any */
import { vi, beforeEach } from 'vitest';
import { mount as baseMount, flushPromises } from '@vue/test-utils';
import { cloneVNode, defineComponent, getCurrentInstance, h, nextTick, ref, watch } from 'vue';

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
    return {
        useContainerBadges: vi.fn((itemsRef: any) => {
            return {
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
            };
        }),
    };
});

const mockBatchReactToSiblings = vi.fn();
const mockHandlePillClick = vi.fn();
const mockHandlePillAuxClick = vi.fn();
let capturedOpenContainerTab: ((container: any) => void) | null = null;
vi.mock('@/composables/useContainerPillInteractions', () => ({
    useContainerPillInteractions: vi.fn((options: {
        items: { value: any[] };
        onOpenContainerTab?: (container: any) => void;
    }) => {
        capturedOpenContainerTab = options.onOpenContainerTab ?? null;
        return {
            getContainersForItem: vi.fn((item: any) => (item as any).containers || []),
            getSiblingItems: vi.fn((containerId: number) => {
                return options.items.value.filter((item: any) => {
                    const containers = (item as any).containers || [];
                    return containers.some((container: any) => container?.id === containerId);
                });
            }),
            getContainer: vi.fn((containerId: number) => {
                for (const item of options.items.value) {
                    const containers = (item as any).containers || [];
                    const container = containers.find((candidate: any) => candidate?.id === containerId);
                    if (container) {
                        return container;
                    }
                }

                return null;
            }),
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
const mockCancelAutoDislikeCountdown = vi.fn();
const mockStartAutoDislikeCountdown = vi.fn();
const mockHasActiveCountdown = vi.fn(() => false);
vi.mock('@/composables/useAutoDislikeQueue', async () => {
    const { ref } = await import('vue');
    return {
        useAutoDislikeQueue: vi.fn(() => ({
            startAutoDislikeCountdown: mockStartAutoDislikeCountdown,
            cancelAutoDislikeCountdown: mockCancelAutoDislikeCountdown,
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
const mockRemove = vi.fn();
const mockLoadNextPage = vi.fn();

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
            const renderedItems = ref<any[]>([]);
            const instance = getCurrentInstance();

            function syncRenderedItems(nextItems: any[] | undefined): void {
                renderedItems.value = Array.isArray(nextItems) ? [...nextItems] : [];
            }

            watch(
                () => props.items,
                (nextItems) => {
                    syncRenderedItems(nextItems);
                },
                { immediate: true, deep: false }
            );

            if (instance) {
                const originalEmit = instance.emit;
                instance.emit = ((event: string, ...args: any[]) => {
                    if (event === 'update:items') {
                        syncRenderedItems(args[0]);
                    }

                    return originalEmit(event, ...args);
                }) as typeof instance.emit;
            }

            const remove = async (itemsOrIds: any) => {
                mockRemove(itemsOrIds);
                const currentItems = renderedItems.value;
                const removals = Array.isArray(itemsOrIds) ? itemsOrIds : [itemsOrIds];
                const itemIdsToRemove = new Set(
                    removals
                        .map((itemOrId: any) => typeof itemOrId === 'object' ? itemOrId?.id : itemOrId)
                        .filter((itemOrId: any) => itemOrId !== undefined && itemOrId !== null)
                );
                const nextItems = currentItems.filter((item: any) => !itemIdsToRemove.has(item?.id));
                if (nextItems.length !== currentItems.length) {
                    emit('update:items', nextItems);
                }
            };

            const restore = async (itemsOrIds: any) => {
                const raw = Array.isArray(itemsOrIds) ? itemsOrIds : [itemsOrIds];
                const itemsToRestore = raw.filter((item: any) => item && typeof item === 'object' && item.id != null);
                if (itemsToRestore.length === 0) {
                    return;
                }

                const existingIds = new Set(renderedItems.value.map((item: any) => item?.id));
                const uniqueItems = itemsToRestore.filter((item: any) => !existingIds.has(item.id));
                if (uniqueItems.length === 0) {
                    return;
                }

                emit('update:items', [...renderedItems.value, ...uniqueItems]);
            };

            const loadNextPage = async () => {
                mockLoadNextPage();
                const getContent = props.getContent ?? props.getPage;
                if (!getContent || nextPage === null || nextPage === undefined) {
                    return;
                }
                const pageToLoad = nextPage;
                currentPage = pageToLoad;
                const result = await getContent(pageToLoad);
                const newItems = result?.items ?? [];
                const nextItems = [...renderedItems.value, ...newItems];
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
                cancel,
                remove,
                restore,
                loadNextPage,
                get isLoading() { return mockIsLoading.value; },
                set isLoading(value: boolean) { mockIsLoading.value = value; },
                get nextPage() { return nextPage; },
                set nextPage(value: number | string | null) { nextPage = value; },
                get hasReachedEnd() { return hasReachedEnd; },
                set hasReachedEnd(value: boolean) { hasReachedEnd = value; },
                get pagesLoaded() { return paginationHistory; },
                set pagesLoaded(value: Array<number | string>) { paginationHistory = value; },
                undo: vi.fn(),
                forget: vi.fn(),
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
                if (!definition) {
                    return h('div', { class: 'masonry-mock', 'data-test': 'masonry-component' });
                }

                const children = renderedItems.value.map((item: any, index: number) => {
                    return cloneVNode(definition, {
                        key: item?.id ?? index,
                        item,
                        remove,
                        index,
                    });
                });

                return h('div', { class: 'masonry-mock', 'data-test': 'masonry-component' }, children as any);
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
vi.mock('@/lib/browseCatalog', async () => {
    const { ref } = await import('vue');
    return {
        createBrowseCatalog: () => ({
            state: {
                availableServices: ref([]),
                availableSources: ref([]),
                localService: ref(null),
            },
            actions: {
                loadServices: vi.fn(),
                loadSources: vi.fn(),
            },
        }),
    };
});


vi.mock('@/utils/reactions', () => ({
    createReactionCallback: vi.fn(() => vi.fn()),
}));

vi.mock('@/actions/App/Http/Controllers/FilesController', () => ({
    incrementPreview: {
        url: (args: number | { file: number }) => `/api/files/${typeof args === 'number' ? args : args.file}/increment-preview`,
    },
    reportPreviewFailure: {
        url: (args: number | { file: number }) => `/api/files/${typeof args === 'number' ? args : args.file}/preview-failure`,
    },
}));

beforeEach(() => {
    vi.clearAllMocks();
    capturedOpenContainerTab = null;
    mockClearAutoDislikeCountdowns.mockClear();
    mockCancelAutoDislikeCountdown.mockClear();
    mockIsLoading.value = false;
    mockCancelLoad.mockClear();
    mockRemove.mockClear();
    mockLoadNextPage.mockClear();

    // Default mock for axios
    mockAxios.get.mockResolvedValue({ data: { items: [], nextPage: null } });
    mockAxios.post.mockResolvedValue({ data: { previewed_count: 0 } });
    mockAxios.put.mockResolvedValue({ data: {} });
    mockAxios.delete.mockResolvedValue({ data: {} });
    mockAxios.patch.mockResolvedValue({ data: {} });
    (window as typeof window & { Echo?: unknown }).Echo = undefined;
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
    mockCancelAutoDislikeCountdown,
    mockStartAutoDislikeCountdown,
    mockHasActiveCountdown,
    mockIsLoading,
    mockCancelLoad,
    mockRemove,
    mockLoadNextPage,
    capturedOpenContainerTab,
};

