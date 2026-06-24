import { flushPromises, mount } from '@vue/test-utils';
import { defineComponent, h, reactive, ref } from 'vue';
import { createMemoryHistory, createRouter } from 'vue-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import TabContentV2 from './TabContentV2.vue';

const testState = vi.hoisted(() => ({ cancelSpy: vi.fn() }));

const status = reactive({
    activeIndex: 0,
    currentCursor: '1',
    errorMessage: null,
    fillCollectedCount: null,
    fillCompletedCalls: 0,
    fillDelayRemainingMs: null,
    fillLoadedCount: 0,
    fillMode: 'idle' as const,
    fillProgress: null,
    fillTargetCalls: null,
    fillTargetCount: null,
    fillTotalCount: null,
    hasNextPage: true,
    hasPreviousPage: false,
    itemCount: 0,
    itemsRevision: 0,
    loadState: 'loaded' as 'failed' | 'loaded' | 'loading',
    nextBoundaryLoadProgress: 0,
    nextCursor: '2',
    pageLoadingLocked: false,
    phase: 'idle' as 'failed' | 'filling' | 'idle' | 'initializing' | 'loading' | 'refreshing',
    previousBoundaryLoadProgress: 0,
    previousCursor: null,
    removedCount: 0,
    removedIds: [] as string[],
    removedRevision: 0,
    surfaceMode: 'list' as const,
});

vi.mock('@/components/ui/toast/use-toast', () => ({ useToast: () => ({ error: vi.fn() }) }));
vi.mock('@/composables/useBrowseGridAutoScrollShortcut', () => ({ useBrowseGridAutoScrollShortcut: vi.fn() }));
vi.mock('@/composables/useBrowseForm', () => ({
    BrowseFormKey: Symbol('BrowseForm'),
    createBrowseForm: () => ({
        data: reactive({ feed: 'online', limit: 20, page: 1, service: 'civit-ai-images', serviceFilters: [], source: 'all' }),
        getData() { return this.data; },
        isLocal: ref(false),
        isLocalMode: ref(false),
        reset: vi.fn(),
    }),
}));
vi.mock('@/composables/useDownloadedReactionPrompt', () => ({
    useDownloadedReactionPrompt: () => ({ data: { open: ref(false) }, prompt: vi.fn() }),
}));
vi.mock('@/composables/useFileViewerData', () => ({
    useFileViewerData: () => ({ fileData: ref(null), isLoadingFileData: ref(false), setFileData: vi.fn() }),
}));
vi.mock('@/composables/useItemPreview', () => ({
    useItemPreview: () => ({ clearPreviewedItems: vi.fn(), preload: vi.fn() }),
}));
vi.mock('@/composables/useLoadedItemsBatchActionConfirmation', () => ({
    useLoadedItemsBatchActionConfirmation: () => ({ pendingAction: ref(null), cancel: vi.fn(), confirm: vi.fn(), request: vi.fn() }),
}));
vi.mock('@/composables/useLocalFileDeletion', () => ({
    useLocalFileDeletion: () => ({
        state: { deleteError: ref(null), deleting: ref(false), dialogOpen: ref(false), itemToDelete: ref(null) },
        actions: { close: vi.fn(), confirm: vi.fn() },
    }),
}));
vi.mock('@/composables/useTabContentBrowseState', () => ({
    useTabContentBrowseState: (options: {
        data: { tab: { value: unknown } };
        events: { onTabDataLoadingChange?: (value: boolean) => void };
        tabId: { value: number | null };
    }) => {
        queueMicrotask(() => {
            options.data.tab.value = {
                id: options.tabId.value ?? 1,
                isActive: true,
                items: [],
                label: 'Browse Tab',
                params: {},
                position: 0,
                updatedAt: null,
            };
            options.events.onTabDataLoadingChange?.(false);
        });

        return {
            state: {
                bootstrapFailed: ref(false),
                isInitializing: ref(false),
                masonryRenderKey: ref(0),
                shouldShowForm: ref(false),
                startPageToken: ref(1),
                totalAvailable: ref(null),
            },
            actions: {
                applyFilters: vi.fn(async () => undefined),
                applyService: vi.fn(async () => undefined),
                goToFirstPage: vi.fn(async () => undefined),
                initialize: vi.fn(async () => undefined),
                updateService: vi.fn(),
            },
        };
    },
}));
vi.mock('@/composables/useTabContentContainerInteractions', () => ({
    useTabContentContainerInteractions: () => ({
        clearHoveredContainer: vi.fn(),
        drawer: { actions: { setOpen: vi.fn() }, derived: { highlightedItemIds: ref(new Set()) }, state: { isOpen: ref(false) } },
        managerRef: ref(null),
        sheet: { actions: { close: vi.fn() }, state: { isOpen: ref(false) } },
    }),
}));
vi.mock('@/composables/useTabContentItemInteractions', () => ({
    useTabContentItemInteractions: () => ({
        performLoadedItemsBulkAction: vi.fn(),
        preload: { onBatchFailures: vi.fn(), onBatchPreloaded: vi.fn(), reset: vi.fn() },
        reactions: { onFileReaction: vi.fn() },
        state: { clearHover: vi.fn() },
        viewer: { onClose: vi.fn(), onOpen: vi.fn() },
    }),
}));
vi.mock('@/composables/useTabContentPromptDialog', () => ({
    useTabContentPromptDialog: () => ({
        data: { currentPromptData: ref(null), promptDataLoading: ref(false), promptDialogItemId: ref(null), promptDialogOpen: ref(false) },
    }),
}));
vi.mock('@/composables/useTabContentV2ContainerBlacklists', () => ({
    useTabContentV2ContainerBlacklists: () => ({
        applyActiveContainerBlacklistFilter: vi.fn(),
        filterItemsByActiveContainerBlacklists: vi.fn((items) => items),
        handleContainerBlacklistChange: vi.fn(),
    }),
}));
vi.mock('@/composables/useTabContentV2FileSheet', () => ({
    useTabContentV2FileSheet: () => ({
        close: vi.fn(),
        closeForFullscreenExit: vi.fn(),
        item: null,
        open: vi.fn(),
        openForItem: vi.fn(),
        presentation: ref('inline'),
        reset: vi.fn(),
        state: reactive({ isOpen: false }),
        targetFileId: ref(null),
    }),
}));
vi.mock('@/composables/useTabContentVibeRemoval', () => ({
    useTabContentVibeRemoval: () => ({
        actions: { cancelLoadedItemsRemoval: vi.fn(), confirm: vi.fn(), isRemovingItem: vi.fn(), openLoadedItemsDialog: vi.fn(), removeItem: vi.fn() },
        state: { canRemoveLoadedItems: ref(false), dialogOpen: ref(false), loadedItemCount: ref(0), removingLoadedItems: ref(false) },
    }),
}));
vi.mock('@/composables/useVibeFillControls', () => ({
    AUTO_SCROLL_SPEED_MAX: 100,
    AUTO_SCROLL_SPEED_MIN: 1,
    FILL_CALL_COUNT_MAX: 10,
    FILL_CALL_COUNT_MIN: 1,
    useVibeFillControls: () => ({
        autoScrollActive: ref(false),
        autoScrollSpeed: ref(1),
        cancelFill: vi.fn(),
        fillActionsDisabled: ref(false),
        fillCallCount: ref(1),
        fillUntilCount: vi.fn(),
        fillUntilEnd: vi.fn(),
        pauseAutoScroll: vi.fn(),
        resumeAutoScroll: vi.fn(),
        setAutoScrollSpeed: vi.fn(),
        setFillCallCount: vi.fn(),
        stopAutoScroll: vi.fn(),
        toggleAutoScroll: vi.fn(),
    }),
}));
vi.mock('@/lib/browseCatalog', () => ({
    createBrowseCatalog: () => ({
        actions: { loadServices: vi.fn(), loadSources: vi.fn() },
        state: { availableServices: ref([]), availableSources: ref([]), localService: ref(null) },
    }),
}));
vi.mock('@/lib/browseTabLabel', () => ({ buildBrowseTabLabel: () => 'Browse Tab' }));
vi.mock('@/lib/browseV2StandaloneItem', () => ({ loadBrowseV2StandaloneFileItem: vi.fn() }));
vi.mock('@/lib/tabContentBrowseBootstrap', () => ({ extractRestoredBrowseSession: () => null }));
vi.mock('@/lib/tabContentV2', () => ({
    createRemovedItemIdSet: (ids: string[]) => new Set(ids),
    createTabContentV2EmptyStatus: () => status,
    createTabContentV2Resolve: () => vi.fn(async () => ({ items: [], nextPage: null })),
    mapFeedItemToVibeItem: (item: unknown) => item,
    normalizeCursor: (cursor: unknown) => cursor,
    resolveOverlayMediaType: () => 'image',
}));
vi.mock('@/lib/tabContentV2FileSync', () => ({ createSyncedFileViewerData: ({ fileViewerData }: { fileViewerData: unknown }) => fileViewerData }));
vi.mock('@/lib/tabContentV2MouseShortcuts', () => ({
    createBrowseV2MouseShortcutHandlers: () => ({ handleAuxClickCapture: vi.fn(), handleClickCapture: vi.fn(), handleContextMenuCapture: vi.fn(), handleMouseDownCapture: vi.fn() }),
}));
vi.mock('@/lib/tabContentV2VibeItems', () => ({
    getFeedItemFromVibeItem: () => null,
    getFeedItemFromVibeOccurrenceTarget: () => null,
    getFeedItemsFromVibeHandle: () => [],
}));
vi.mock('@/utils/localReactionState', () => ({
    isPositiveOnlyLocalView: () => false,
    matchesLocalViewFilters: () => true,
}));
vi.mock('@/composables/useBrowseV2SurfaceRouteSync', () => ({
    useBrowseV2SurfaceRouteSync: () => ({
        handleVibeActiveIndexUpdate: vi.fn(),
        handleVibeSurfaceModeUpdate: vi.fn(),
        isClosingFullscreenRoute: ref(false),
    }),
}));
vi.mock('./TabContentV2View.vue', () => ({
    default: defineComponent({
        name: 'TabContentV2View',
        props: {
            headerMasonry: { type: Object, default: null },
            setVibeHandle: { type: Function, default: null },
        },
        setup(props) {
            props.setVibeHandle?.({
                autoScroll: vi.fn(),
                cancel: () => {
                    testState.cancelSpy();
                    status.phase = 'idle';
                },
                cancelFill: vi.fn(),
                fillUntil: vi.fn(),
                fillUntilEnd: vi.fn(),
                getItems: () => [],
                lockPageLoading: vi.fn(),
                loadNext: vi.fn(),
                remove: vi.fn(),
                restore: vi.fn(),
                status,
                unlockPageLoading: vi.fn(),
            });

            return () => h('button', {
                'data-testid': 'cancel-active-vibe-load',
                onClick: () => props.headerMasonry?.cancel?.(),
            });
        },
    }),
}));

async function mountSubject(onLoadingChange: (isLoading: boolean) => void) {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [{ path: '/browse', name: 'browse', component: { template: '<div />' } }],
    });
    await router.push('/browse');
    await router.isReady();

    const wrapper = mount(TabContentV2, {
        props: { availableServices: [], onLoadingChange, onReaction: vi.fn(), tabId: 1 },
        global: { plugins: [router] },
    });
    await flushPromises();
    await flushPromises();

    return wrapper;
}

describe('TabContentV2 loading state', () => {
    beforeEach(() => {
        testState.cancelSpy.mockReset();
        status.loadState = 'loaded';
        status.phase = 'idle';
    });

    it('clears active Vibe loading state after cancelling while phase is loading', async () => {
        const onLoadingChange = vi.fn();
        status.phase = 'loading';

        const wrapper = await mountSubject(onLoadingChange);
        onLoadingChange.mockClear();

        await wrapper.get('[data-testid="cancel-active-vibe-load"]').trigger('click');
        await flushPromises();

        expect(testState.cancelSpy).toHaveBeenCalledTimes(1);
        expect(onLoadingChange).toHaveBeenCalledWith(false);
    });
});
