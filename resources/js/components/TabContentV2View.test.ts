import { mount } from '@vue/test-utils';
import { defineComponent, h, ref } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import TabContentV2View from './TabContentV2View.vue';

const vibeLayoutSpy = vi.hoisted(() => vi.fn());
const browseV2StatusBarSpy = vi.hoisted(() => vi.fn());
const testState = vi.hoisted(() => ({
    gridOverlayItem: {
        fileId: 12,
        feedItem: {
            id: 12,
            previewed_count: 1,
            reaction: null,
            seen_count: 0,
        },
        id: 'grid-item',
        type: 'image',
    } as Record<string, unknown>,
    fullscreenOverlayItem: {
        fileId: 11,
        feedItem: {
            id: 11,
            previewed_count: 1,
            reaction: null,
            seen_count: 0,
        },
        id: 'overlay-item',
        type: 'image',
    } as Record<string, unknown>,
}));

const testStub = defineComponent({
    name: 'TestStub',
    render() {
        return h('div');
    },
});

const browseV2StatusBarStub = defineComponent({
    name: 'BrowseV2StatusBarStub',
    props: {
        status: { type: Object, required: true },
        totalAvailable: { default: null },
        cancelFill: { type: Function, default: null },
        canTogglePageLoadingLock: { type: Boolean, default: false },
        pageLoadingLocked: { type: Boolean, default: false },
        togglePageLoadingLock: { type: Function, default: null },
    },
    setup(props) {
        browseV2StatusBarSpy(props);

        return () => h('div', { 'data-testid': 'browse-v2-status-bar-stub' });
    },
});

const defaultStubs = {
    BrowseV2StatusBar: browseV2StatusBarStub,
    Button: testStub,
    ContainerBlacklistManager: testStub,
    DownloadedReactionDialog: testStub,
    FileReactions: testStub,
    FileViewerSheet: testStub,
    LoadedItemsBatchActionDialog: testStub,
    LoadedItemsRemovalDialog: testStub,
    LocalFileDeleteDialog: testStub,
    PanelRightOpen: testStub, TabContentContainerDrawer: testStub,
    TabContentContainerSheet: testStub,
    TabContentPromptDialog: testStub,
    TabContentServiceHeader: testStub,
    TabContentStartForm: testStub,
    TabContentV2GridOverlay: testStub,
};

vi.mock('@wyxos/vibe', () => ({
    VibeLayout: defineComponent({
        name: 'VibeLayout',
        emits: ['asset-errors', 'asset-loads', 'items-change', 'update:activeIndex', 'update:surfaceMode'],
        props: {
            activeIndex: { type: Number, default: 0 },
            emptyStateMode: { type: String, default: 'inline' },
            fillDelayMaxMs: { type: Number, default: undefined },
            fillDelayMs: { type: Number, default: undefined },
            fillDelayStepMs: { type: Number, default: undefined },
            loopFullscreenVideo: { type: Boolean, default: false },
            showEndBadge: { type: Boolean, default: true },
            showStatusBadges: { type: Boolean, default: true },
            surfaceMode: { type: String, default: 'list' },
        },
        setup(props, { attrs, emit, slots }) {
            vibeLayoutSpy({
                attrs,
                props: {
                    activeIndex: props.activeIndex,
                    emptyStateMode: props.emptyStateMode,
                    fillDelayMaxMs: props.fillDelayMaxMs,
                    fillDelayMs: props.fillDelayMs,
                    fillDelayStepMs: props.fillDelayStepMs,
                    loopFullscreenVideo: props.loopFullscreenVideo,
                    showEndBadge: props.showEndBadge,
                    showStatusBadges: props.showStatusBadges,
                    surfaceMode: props.surfaceMode,
                },
                slotNames: Object.keys(slots),
            });

            return () => h('div', {
                class: attrs.class,
                'data-testid': 'vibe-layout',
                'data-slot-names': Object.keys(slots).join(','),
            }, [
                h('button', {
                    'data-testid': 'emit-active-index',
                    onClick: () => emit('update:activeIndex', 7),
                }),
                h('button', {
                    'data-testid': 'emit-surface-mode',
                    onClick: () => emit('update:surfaceMode', 'list'),
                }),
                h('button', {
                    'data-testid': 'emit-asset-loads',
                    onClick: () => emit('asset-loads', []),
                }),
                h('button', {
                    'data-testid': 'emit-asset-errors',
                    onClick: () => emit('asset-errors', []),
                }),
                h('button', {
                    'data-testid': 'emit-items-change',
                    onClick: () => emit('items-change', [testState.fullscreenOverlayItem]),
                }),
                slots['grid-item-overlay']?.({
                    active: false,
                    focused: false,
                    hovered: false,
                    index: 0,
                    item: testState.gridOverlayItem,
                    openFullscreen: vi.fn(),
                }),
                slots['fullscreen-header-actions']?.({
                    hasNextPage: true,
                    index: props.activeIndex,
                    item: testState.fullscreenOverlayItem,
                    loading: false,
                    paginationDetail: null,
                    total: 20,
                }),
                slots['fullscreen-footer']?.({
                    hasNextPage: true,
                    index: props.activeIndex,
                    item: testState.fullscreenOverlayItem,
                    loading: false,
                    paginationDetail: null,
                    total: 20,
                }),
                slots['grid-footer']?.(),
            ]);
        },
    }),
}));

function createProps() {
    return {
        activeIndex: 1,
        availableServices: [{ key: 'civit-ai-images', label: 'CivitAI Images' }],
        availableSources: [],
        applyFilters: vi.fn(async () => undefined),
        applyService: vi.fn(async () => undefined),
        cancelFill: vi.fn(),
        cancelBatchAction: vi.fn(),
        closeGridFileSheet: vi.fn(),
        closeViewerFileSheet: vi.fn(),
        confirmBatchAction: vi.fn(),
        containerInteractions: {
            managerRef: ref(null),
            isBlacklistable: vi.fn(() => true),
            drawer: {
                state: { isOpen: ref(false) },
                derived: { container: ref(null), highlightedItemIds: ref(new Set<number>()), items: ref([]) },
                actions: { setOpen: vi.fn() },
            },
            sheet: { state: { isOpen: ref(false) }, derived: { container: ref(null), items: ref([]) }, actions: { close: vi.fn() } },
        },
        currentVisibleItem: null,
        downloadedReactionPrompt: {
            data: { open: ref(false) },
            chooseReact: vi.fn(),
            chooseRedownload: vi.fn(),
            close: vi.fn(),
            setOpen: vi.fn(),
        },
        gridFileSheetState: { isOpen: false },
        gridFileSheetItem: null,
        viewerFileSheetState: { isOpen: false },
        viewerFileSheetItem: null,
        fileViewerData: {
            fileData: ref(null),
            isLoadingFileData: ref(false),
            setFileData: vi.fn(),
        },
        form: {
            data: {
                limit: 20,
                feed: 'online',
                source: null,
            },
            reset: vi.fn(),
        },
        goToFirstPage: vi.fn(async () => undefined),
        handleAssetErrors: vi.fn(),
        handleAssetLoads: vi.fn(),
        handleContainerBlacklistChange: vi.fn(),
        handleItemsChange: vi.fn(),
        handleReaction: vi.fn(),
        headerMasonry: null,
        isFilterSheetOpen: false,
        itemInteractions: {
            performLoadedItemsBulkAction: vi.fn(),
            reactions: {
                onFileBlacklist: vi.fn(async () => 1),
            },
        },
        localFileDeletion: {
            state: {
                dialogOpen: ref(false),
                itemToDelete: ref(null),
                deleting: ref(false),
                deleteError: ref(null),
            },
            actions: {
                close: vi.fn(),
                confirm: vi.fn(async () => false),
                openFromFileSheet: vi.fn(),
            },
        },
        localService: null,
        loadNext: vi.fn(async () => undefined),
        masonryRenderKey: 0,
        mouseShortcuts: {
            handleAuxClickCapture: vi.fn(),
            handleClickCapture: vi.fn(),
            handleContextMenuCapture: vi.fn(),
            handleMouseDownCapture: vi.fn(),
        },
        openViewerFileSheet: vi.fn(),
        openGridFileSheetForItem: vi.fn(),
        promptDialog: {
            data: {
                promptDialogOpen: ref(false),
                promptDialogItemId: ref(null),
                promptDataLoading: ref(false),
                currentPromptData: ref(null),
            },
            clear: vi.fn(),
            setOpen: vi.fn(),
            select: vi.fn(),
            copy: vi.fn(),
            close: vi.fn(),
        },
        resolve: vi.fn(async () => ({ items: [], nextPage: null })),
        setFilterSheetOpen: vi.fn(),
        setLocalMode: vi.fn(),
        setVibeHandle: vi.fn(),
        shouldShowForm: false,
        surfaceMode: 'fullscreen' as const,
        tab: {
            id: 7,
            label: 'Browse Tab',
            params: {},
            position: 0,
            isActive: true,
            updatedAt: null,
        },
        totalAvailable: null,
        updateFeed: vi.fn(),
        updateActiveIndex: vi.fn(),
        updateSource: vi.fn(),
        updateSurfaceMode: vi.fn(),
        updateService: vi.fn(async () => undefined),
        vibeInitialCursor: '1',
        vibeInitialState: undefined,
        vibeStatus: {
            activeIndex: 1,
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
            itemCount: 20, itemsRevision: 0,
            loadState: 'loaded' as const,
            nextBoundaryLoadProgress: 0,
            nextCursor: '2',
            pageLoadingLocked: false,
            phase: 'idle' as const,
            previousBoundaryLoadProgress: 0,
            previousCursor: null,
            removedCount: 0, removedIds: [], removedRevision: 0,
            surfaceMode: 'list' as const,
        },
        viewerKey: 'viewer-key',
    };
}

function mountView(props: ReturnType<typeof createProps> = createProps()) {
    return mount(TabContentV2View, { props, global: { stubs: defaultStubs } });
}

describe('TabContentV2View', () => {
    beforeEach(() => {
        vibeLayoutSpy.mockClear();
        browseV2StatusBarSpy.mockClear();
        testState.gridOverlayItem = {
            fileId: 12,
            feedItem: {
                id: 12,
                previewed_count: 1,
                reaction: null,
                seen_count: 0,
            },
            id: 'grid-item',
            type: 'image',
        };
        testState.fullscreenOverlayItem = {
            fileId: 11,
            feedItem: {
                id: 11,
                previewed_count: 1,
                reaction: null,
                seen_count: 0,
            },
            id: 'overlay-item',
            type: 'image',
        };
    });

    it('passes the controlled surface props through to VibeLayout', () => {
        const wrapper = mountView();

        expect(wrapper.get('[data-testid="vibe-layout"]').attributes('data-slot-names')).toContain('grid-footer');
        expect(wrapper.get('[data-testid="vibe-layout"]').attributes('data-slot-names')).toContain('fullscreen-aside');
        expect(wrapper.get('[data-testid="vibe-layout"]').attributes('data-slot-names')).toContain('fullscreen-footer');
        expect(wrapper.get('[data-testid="vibe-layout"]').attributes('data-slot-names')).toContain('fullscreen-header-actions');
        expect(vibeLayoutSpy).toHaveBeenCalled();
        expect(vibeLayoutSpy.mock.calls[0][0].props.activeIndex).toBe(1);
        expect(vibeLayoutSpy.mock.calls[0][0].props.emptyStateMode).toBe('hidden');
        expect(vibeLayoutSpy.mock.calls[0][0].props.fillDelayMaxMs).toBe(15000);
        expect(vibeLayoutSpy.mock.calls[0][0].props.fillDelayMs).toBe(2000);
        expect(vibeLayoutSpy.mock.calls[0][0].props.fillDelayStepMs).toBe(1000);
        expect(vibeLayoutSpy.mock.calls[0][0].props.loopFullscreenVideo).toBe(true);
        expect(vibeLayoutSpy.mock.calls[0][0].props.showEndBadge).toBe(false);
        expect(vibeLayoutSpy.mock.calls[0][0].props.surfaceMode).toBe('fullscreen');
        expect(vibeLayoutSpy.mock.calls[0][0].props.showStatusBadges).toBe(false);
    });

    it('removes Vibe fill delay while browsing the local service', () => {
        const props = createProps();
        props.form.data.feed = 'local';

        mountView(props);

        expect(vibeLayoutSpy.mock.calls[0][0].props.fillDelayMs).toBe(0);
        expect(vibeLayoutSpy.mock.calls[0][0].props.fillDelayStepMs).toBe(0);
        expect(vibeLayoutSpy.mock.calls[0][0].props.fillDelayMaxMs).toBe(0);
    });

    it('does not provide Vibe status badge slots when Atlas disables them', () => {
        const wrapper = mountView();

        const slotNames = wrapper.get('[data-testid="vibe-layout"]').attributes('data-slot-names');

        expect(slotNames).not.toContain('grid-status');
        expect(slotNames).not.toContain('fullscreen-status');
    });

    it('wires Vibe emitted events through to the Atlas callbacks', async () => {
        const props = createProps();
        const wrapper = mountView(props);

        await wrapper.get('[data-testid="emit-active-index"]').trigger('click');
        await wrapper.get('[data-testid="emit-surface-mode"]').trigger('click');
        await wrapper.get('[data-testid="emit-asset-loads"]').trigger('click');
        await wrapper.get('[data-testid="emit-asset-errors"]').trigger('click');
        await wrapper.get('[data-testid="emit-items-change"]').trigger('click');

        expect(props.updateActiveIndex).toHaveBeenCalledWith(7);
        expect(props.updateSurfaceMode).toHaveBeenCalledWith('list');
        expect(props.handleAssetLoads).toHaveBeenCalledWith([]);
        expect(props.handleAssetErrors).toHaveBeenCalledWith([]);
        expect(props.handleItemsChange).toHaveBeenCalledWith([testState.fullscreenOverlayItem]);
    });

    it('dims grid overlay cards that are not in the active container drawer sibling set', () => {
        const props = createProps();
        props.containerInteractions.drawer.derived.highlightedItemIds = ref(new Set([99]));
        const gridOverlaySpy = vi.fn();
        const gridOverlayStub = defineComponent({
            name: 'TabContentV2GridOverlayStub',
            props: {
                dimmed: { type: Boolean, default: false },
            },
            setup(stubProps) {
                gridOverlaySpy(stubProps);

                return () => h('div', { 'data-testid': 'grid-overlay-stub' });
            },
        });

        mount(TabContentV2View, {
            props,
            global: {
                stubs: {
                    ...defaultStubs,
                    TabContentV2GridOverlay: gridOverlayStub,
                },
            },
        });

        expect(gridOverlaySpy).toHaveBeenCalled();
        expect(gridOverlaySpy.mock.calls[0][0].dimmed).toBe(true);
    });

    it('passes backend available total to the status bar', () => {
        const props = createProps();
        props.totalAvailable = 312;

        mountView(props);

        expect(browseV2StatusBarSpy).toHaveBeenCalled();
        expect(browseV2StatusBarSpy.mock.calls[0][0].totalAvailable).toBe(312);
    });

    it('passes status bar lock state through to the footer overlay', () => {
        const props = createProps();
        const lockPageLoading = vi.fn();
        const unlockPageLoading = vi.fn();

        props.headerMasonry = {
            isLoading: false,
            lockPageLoading,
            pageLoadingLocked: false,
            remove: vi.fn(),
            restore: vi.fn(),
            unlockPageLoading,
        };

        mountView(props);

        expect(browseV2StatusBarSpy).toHaveBeenCalled();

        const statusBarProps = browseV2StatusBarSpy.mock.calls[0][0];

        expect(statusBarProps.cancelFill).toBe(props.cancelFill);
        expect(statusBarProps.canTogglePageLoadingLock).toBe(true);
        expect(statusBarProps.pageLoadingLocked).toBe(false);
        expect('performLoadedItemsBulkAction' in statusBarProps).toBe(false);

        statusBarProps.togglePageLoadingLock();
        expect(lockPageLoading).toHaveBeenCalledTimes(1);

        props.headerMasonry.pageLoadingLocked = true;
        statusBarProps.togglePageLoadingLock();
        expect(unlockPageLoading).toHaveBeenCalledTimes(1);
    });

    it('overlays the list-mode file sheet without reserving grid space for grid item info actions', async () => {
        const props = createProps();
        props.gridFileSheetState.isOpen = true;
        props.surfaceMode = 'list';

        const wrapper = mountView(props);

        expect(wrapper.find('[data-test="file-viewer-sheet-overlay"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="file-viewer-sheet-inline"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="vibe-layout"]').classes()).not.toContain('atlas-file-viewer-wide-aside');
        expect(vibeLayoutSpy.mock.calls[0][0].attrs.style).toEqual({});
        const overlayTransition = wrapper.findAllComponents({ name: 'Transition' })
            .find((transition) => String(transition.props('enterFromClass')).includes('translate-x-full'));

        expect(overlayTransition?.props('leaveToClass')).toContain('translate-x-full');
        expect(overlayTransition?.props('enterActiveClass')).toContain('duration-500');
        expect(overlayTransition?.props('leaveActiveClass')).toContain('duration-300');

        await wrapper.get('[data-test="file-viewer-sheet-overlay"]').trigger('click');
        expect(props.closeGridFileSheet).toHaveBeenCalledTimes(1);
    });

    it('renders list-mode file sheets as a grid overlay even when the saved sheet state is inline', () => {
        const props = createProps();
        props.gridFileSheetState.isOpen = true;
        props.surfaceMode = 'list';

        const wrapper = mountView(props);

        expect(wrapper.find('[data-test="file-viewer-sheet-overlay"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="file-viewer-sheet-inline"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="vibe-layout"]').classes()).not.toContain('atlas-file-viewer-wide-aside');
        expect(vibeLayoutSpy.mock.calls[0][0].attrs.style).toEqual({});
    });

    it('does not copy viewer-owned visibility into the list-mode grid sheet', () => {
        const props = createProps();
        const sheetSpy = vi.fn();
        props.surfaceMode = 'list';
        props.viewerFileSheetState.isOpen = true;
        props.gridFileSheetState.isOpen = false;
        const fileViewerSheetStub = defineComponent({
            props: { isOpen: Boolean },
            setup(stubProps) {
                sheetSpy(stubProps);
                return () => h('div');
            },
        });

        const wrapper = mount(TabContentV2View, { props, global: { stubs: { ...defaultStubs, FileViewerSheet: fileViewerSheetStub } } });

        expect(wrapper.find('[data-test="file-viewer-sheet-overlay"]').exists()).toBe(false);
        expect(sheetSpy.mock.calls[0][0].isOpen).toBe(false);
    });

    it('uses loaded file data for the list-mode file sheet id when no sheet item is pinned', () => {
        const props = createProps();
        const fileViewerSheetSpy = vi.fn();
        const fileViewerSheetStub = defineComponent({ props: { fileId: { type: Number, default: null } }, setup: (stubProps) => (fileViewerSheetSpy(stubProps), () => h('div')) });
        props.gridFileSheetState.isOpen = true; props.surfaceMode = 'list'; props.gridFileSheetItem = null; (props.fileViewerData.fileData as { value: { id: number } | null }).value = { id: 222 };
        mount(TabContentV2View, { props, global: { stubs: { ...defaultStubs, FileViewerSheet: fileViewerSheetStub } } });
        expect(fileViewerSheetSpy.mock.calls[0][0].fileId).toBe(222);
    });

});
