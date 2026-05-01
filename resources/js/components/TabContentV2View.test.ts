import { mount } from '@vue/test-utils';
import { defineComponent, h, ref } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import TabContentV2View from './TabContentV2View.vue';

const vibeLayoutSpy = vi.hoisted(() => vi.fn());
const browseV2StatusBarSpy = vi.hoisted(() => vi.fn());
const testState = vi.hoisted(() => ({
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
        bulkActionsDisabled: { type: Boolean, default: true },
        cancelFill: { type: Function, default: null },
        canTogglePageLoadingLock: { type: Boolean, default: false },
        pageLoadingLocked: { type: Boolean, default: false },
        performLoadedItemsBulkAction: { type: Function, default: null },
        togglePageLoadingLock: { type: Function, default: null },
    },
    setup(props) {
        browseV2StatusBarSpy(props);

        return () => h('div', { 'data-testid': 'browse-v2-status-bar-stub' });
    },
});

vi.mock('@wyxos/vibe', () => ({
    VibeLayout: defineComponent({
        name: 'VibeLayout',
        emits: ['asset-errors', 'asset-loads', 'items-change', 'update:activeIndex', 'update:surfaceMode'],
        props: {
            activeIndex: { type: Number, default: 0 },
            emptyStateMode: { type: String, default: 'inline' },
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
                    loopFullscreenVideo: props.loopFullscreenVideo,
                    showEndBadge: props.showEndBadge,
                    showStatusBadges: props.showStatusBadges,
                    surfaceMode: props.surfaceMode,
                },
                slotNames: Object.keys(slots),
            });

            return () => h('div', {
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
                slots['fullscreen-overlay']?.({
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
        cancelFill: vi.fn(), cancelLoad: vi.fn(),
        closeFileSheet: vi.fn(),
        containerInteractions: {
            managerRef: ref(null),
            drawer: {
                state: { isOpen: ref(false) },
                derived: { container: ref(null), items: ref([]) },
                actions: { setOpen: vi.fn() },
            },
        },
        currentVisibleItem: null,
        downloadedReactionPrompt: {
            data: { open: ref(false) },
            chooseReact: vi.fn(),
            chooseRedownload: vi.fn(),
            close: vi.fn(),
            setOpen: vi.fn(),
        },
        fileSheetState: { isOpen: false },
        fileViewerData: {
            fileData: ref(null),
            isLoadingFileData: ref(false),
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
                confirm: vi.fn(),
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
        openFileSheet: vi.fn(),
        promptDialog: {
            data: {
                promptDialogOpen: ref(false),
                promptDialogItemId: ref(null),
                promptDataLoading: ref(false),
                currentPromptData: ref(null),
            },
            setOpen: vi.fn(),
            copy: vi.fn(),
            openTestPage: vi.fn(),
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
            itemCount: 20,
            loadState: 'loaded' as const,
            nextBoundaryLoadProgress: 0,
            nextCursor: '2',
            pageLoadingLocked: false,
            phase: 'idle' as const,
            previousBoundaryLoadProgress: 0,
            previousCursor: null,
            removedCount: 0,
            removedIds: [],
            surfaceMode: 'list' as const,
        },
        viewerKey: 'viewer-key',
    };
}

describe('TabContentV2View', () => {
    beforeEach(() => {
        vibeLayoutSpy.mockClear();
        browseV2StatusBarSpy.mockClear();
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
        const wrapper = mount(TabContentV2View, {
            props: createProps(),
            global: {
                stubs: {
                    BrowseV2StatusBar: browseV2StatusBarStub,
                    Button: testStub,
                    ContainerBlacklistManager: testStub,
                    DownloadedReactionDialog: testStub,
                    FileReactions: testStub,
                    FileViewerSheet: testStub,
                    LocalFileDeleteDialog: testStub,
                    TabContentContainerDrawer: testStub,
                    TabContentPromptDialog: testStub,
                    TabContentServiceHeader: testStub,
                    TabContentStartForm: testStub,
                    TabContentV2GridOverlay: testStub,
                    PanelRightOpen: testStub,
                },
            },
        });

        expect(wrapper.get('[data-testid="vibe-layout"]').attributes('data-slot-names')).toContain('grid-footer');
        expect(vibeLayoutSpy).toHaveBeenCalled();
        expect(vibeLayoutSpy.mock.calls[0][0].props.activeIndex).toBe(1);
        expect(vibeLayoutSpy.mock.calls[0][0].props.emptyStateMode).toBe('hidden');
        expect(vibeLayoutSpy.mock.calls[0][0].props.loopFullscreenVideo).toBe(true);
        expect(vibeLayoutSpy.mock.calls[0][0].props.showEndBadge).toBe(false);
        expect(vibeLayoutSpy.mock.calls[0][0].props.surfaceMode).toBe('fullscreen');
        expect(vibeLayoutSpy.mock.calls[0][0].props.showStatusBadges).toBe(false);
    });

    it('does not provide Vibe status badge slots when Atlas disables them', () => {
        const wrapper = mount(TabContentV2View, {
            props: createProps(),
            global: {
                stubs: {
                    BrowseV2StatusBar: browseV2StatusBarStub,
                    Button: testStub,
                    ContainerBlacklistManager: testStub,
                    DownloadedReactionDialog: testStub,
                    FileReactions: testStub,
                    FileViewerSheet: testStub,
                    LocalFileDeleteDialog: testStub,
                    TabContentContainerDrawer: testStub,
                    TabContentPromptDialog: testStub,
                    TabContentServiceHeader: testStub,
                    TabContentStartForm: testStub,
                    TabContentV2GridOverlay: testStub,
                },
            },
        });

        const slotNames = wrapper.get('[data-testid="vibe-layout"]').attributes('data-slot-names');

        expect(slotNames).not.toContain('grid-status');
        expect(slotNames).not.toContain('fullscreen-status');
    });

    it('wires Vibe emitted events through to the Atlas callbacks', async () => {
        const props = createProps();
        const wrapper = mount(TabContentV2View, {
            props,
            global: {
                stubs: {
                    BrowseV2StatusBar: browseV2StatusBarStub,
                    Button: testStub,
                    ContainerBlacklistManager: testStub,
                    DownloadedReactionDialog: testStub,
                    FileReactions: testStub,
                    FileViewerSheet: testStub,
                    LocalFileDeleteDialog: testStub,
                    TabContentContainerDrawer: testStub,
                    TabContentPromptDialog: testStub,
                    TabContentServiceHeader: testStub,
                    TabContentStartForm: testStub,
                    TabContentV2GridOverlay: testStub,
                },
            },
        });

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

    it('passes backend available total to the status bar', () => {
        const props = createProps();
        props.totalAvailable = 312;

        mount(TabContentV2View, {
            props,
            global: {
                stubs: {
                    BrowseV2StatusBar: browseV2StatusBarStub,
                    Button: testStub,
                    ContainerBlacklistManager: testStub,
                    DownloadedReactionDialog: testStub,
                    FileReactions: testStub,
                    FileViewerSheet: testStub,
                    LocalFileDeleteDialog: testStub,
                    TabContentContainerDrawer: testStub,
                    TabContentPromptDialog: testStub,
                    TabContentServiceHeader: testStub,
                    TabContentStartForm: testStub,
                    TabContentV2GridOverlay: testStub,
                },
            },
        });

        expect(browseV2StatusBarSpy).toHaveBeenCalled();
        expect(browseV2StatusBarSpy.mock.calls[0][0].totalAvailable).toBe(312);
    });

    it('passes status bar action handlers and lock state through to the footer overlay', () => {
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

        mount(TabContentV2View, {
            props,
            global: {
                stubs: {
                    BrowseV2StatusBar: browseV2StatusBarStub,
                    Button: testStub,
                    ContainerBlacklistManager: testStub,
                    DownloadedReactionDialog: testStub,
                    FileReactions: testStub,
                    FileViewerSheet: testStub,
                    LocalFileDeleteDialog: testStub,
                    TabContentContainerDrawer: testStub,
                    TabContentPromptDialog: testStub,
                    TabContentServiceHeader: testStub,
                    TabContentStartForm: testStub,
                    TabContentV2GridOverlay: testStub,
                },
            },
        });

        expect(browseV2StatusBarSpy).toHaveBeenCalled();

        const statusBarProps = browseV2StatusBarSpy.mock.calls[0][0];

        expect(statusBarProps.bulkActionsDisabled).toBe(false);
        expect(statusBarProps.cancelFill).toBe(props.cancelFill);
        expect(statusBarProps.canTogglePageLoadingLock).toBe(true);
        expect(statusBarProps.pageLoadingLocked).toBe(false);
        expect(statusBarProps.performLoadedItemsBulkAction).toBe(props.itemInteractions.performLoadedItemsBulkAction);

        statusBarProps.togglePageLoadingLock();
        expect(lockPageLoading).toHaveBeenCalledTimes(1);

        props.headerMasonry.pageLoadingLocked = true;
        statusBarProps.togglePageLoadingLock();
        expect(unlockPageLoading).toHaveBeenCalledTimes(1);
    });

    it('offsets fullscreen reactions above the media bar for audio and video items', () => {
        for (const type of ['audio', 'video'] as const) {
            testState.fullscreenOverlayItem = {
                fileId: type === 'audio' ? 44711 : 2853735,
                feedItem: {
                    id: type === 'audio' ? 44711 : 2853735,
                    previewed_count: 1,
                    reaction: null,
                    seen_count: 0,
                },
                id: `${type}-overlay-item`,
                type,
            };

            const wrapper = mount(TabContentV2View, {
                props: createProps(),
                global: {
                    stubs: {
                        BrowseV2StatusBar: browseV2StatusBarStub,
                        Button: testStub,
                        ContainerBlacklistManager: testStub,
                        DownloadedReactionDialog: testStub,
                        FileReactions: testStub,
                        FileViewerSheet: testStub,
                        LocalFileDeleteDialog: testStub,
                        TabContentContainerDrawer: testStub,
                        TabContentPromptDialog: testStub,
                        TabContentServiceHeader: testStub,
                        TabContentStartForm: testStub,
                        TabContentV2GridOverlay: testStub,
                    },
                },
            });

            expect(wrapper.get('[data-testid="browse-fullscreen-reactions"]').attributes('class')).toContain('bottom-[calc(env(safe-area-inset-bottom,0px)+6.5rem)]');
            expect(wrapper.get('[data-testid="browse-fullscreen-reactions"]').attributes('class')).toContain('max-[720px]:bottom-[calc(env(safe-area-inset-bottom,0px)+8rem)]');

            wrapper.unmount();
        }
    });

    it('passes fullscreen blacklist state and action to FileReactions', async () => {
        testState.fullscreenOverlayItem.feedItem = {
            id: 11,
            blacklisted_at: '2026-04-30T00:00:00Z',
            previewed_count: 1,
            reaction: null,
            seen_count: 0,
        };
        const props = createProps();
        const fileReactionsSpy = vi.fn();

        const fileReactionsStub = defineComponent({
            name: 'FileReactionsStub',
            emits: ['blacklist'],
            props: {
                blacklistedAt: { type: String, default: null },
            },
            setup(stubProps, { emit }) {
                fileReactionsSpy(stubProps);

                return () => h('button', {
                    'data-testid': 'fullscreen-blacklist-trigger',
                    onClick: () => emit('blacklist'),
                });
            },
        });

        const wrapper = mount(TabContentV2View, {
            props,
            global: {
                stubs: {
                    BrowseV2StatusBar: browseV2StatusBarStub,
                    Button: testStub,
                    ContainerBlacklistManager: testStub,
                    DownloadedReactionDialog: testStub,
                    FileReactions: fileReactionsStub,
                    FileViewerSheet: testStub,
                    LocalFileDeleteDialog: testStub,
                    TabContentContainerDrawer: testStub,
                    TabContentPromptDialog: testStub,
                    TabContentServiceHeader: testStub,
                    TabContentStartForm: testStub,
                    TabContentV2GridOverlay: testStub,
                },
            },
        });

        expect(fileReactionsSpy).toHaveBeenCalledWith(expect.objectContaining({
            blacklistedAt: '2026-04-30T00:00:00Z',
        }));

        await wrapper.get('[data-testid="fullscreen-blacklist-trigger"]').trigger('click');

        expect(props.itemInteractions.reactions.onFileBlacklist).toHaveBeenCalledWith(testState.fullscreenOverlayItem.feedItem);
    });

});
