import { mount } from '@vue/test-utils';
import { defineComponent, h, ref } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import TabContentV2View from './TabContentV2View.vue';

const vibeLayoutSpy = vi.hoisted(() => vi.fn());

const testStub = defineComponent({
    name: 'TestStub',
    render() {
        return h('div');
    },
});

vi.mock('@wyxos/vibe', () => ({
    VibeLayout: defineComponent({
        name: 'VibeLayout',
        emits: ['asset-errors', 'asset-loads', 'update:activeIndex', 'update:surfaceMode'],
        props: {
            activeIndex: { type: Number, default: 0 },
            showStatusBadges: { type: Boolean, default: true },
            surfaceMode: { type: String, default: 'list' },
        },
        setup(props, { attrs, emit, slots }) {
            vibeLayoutSpy({
                attrs,
                props: {
                    activeIndex: props.activeIndex,
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
        cancelLoad: vi.fn(),
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
        handleLoadedItemsAction: vi.fn(),
        handleReaction: vi.fn(),
        headerMasonry: null,
        isFilterSheetOpen: false,
        itemInteractions: {},
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
        loadPrevious: vi.fn(async () => undefined),
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
        updateFeed: vi.fn(),
        updateActiveIndex: vi.fn(),
        retryLoad: vi.fn(async () => undefined),
        updateSource: vi.fn(),
        updateSurfaceMode: vi.fn(),
        updateService: vi.fn(async () => undefined),
        vibeFeedMode: 'dynamic' as const,
        vibeInitialCursor: '1',
        vibeInitialState: undefined,
        vibeStatus: {
            activeIndex: 1,
            currentCursor: '1',
            errorMessage: null,
            fillCollectedCount: null,
            fillDelayRemainingMs: null,
            fillTargetCount: null,
            hasNextPage: true,
            hasPreviousPage: false,
            isAutoMode: true,
            itemCount: 20,
            loadState: 'loaded' as const,
            mode: 'dynamic' as const,
            nextCursor: '2',
            phase: 'idle' as const,
            previousCursor: null,
            removedCount: 0,
            surfaceMode: 'list' as const,
        },
        viewerKey: 'viewer-key',
    };
}

describe('TabContentV2View', () => {
    beforeEach(() => {
        vibeLayoutSpy.mockClear();
    });

    it('passes the controlled surface props through to VibeLayout', () => {
        const wrapper = mount(TabContentV2View, {
            props: createProps(),
            global: {
                stubs: {
                    BrowseV2StatusBar: testStub,
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
                    ArrowLeft: testStub,
                    PanelRightOpen: testStub,
                },
            },
        });

        expect(wrapper.get('[data-testid="vibe-layout"]').attributes('data-slot-names')).toContain('grid-footer');
        expect(vibeLayoutSpy).toHaveBeenCalled();
        expect(vibeLayoutSpy.mock.calls[0][0].props.activeIndex).toBe(1);
        expect(vibeLayoutSpy.mock.calls[0][0].props.surfaceMode).toBe('fullscreen');
        expect(vibeLayoutSpy.mock.calls[0][0].props.showStatusBadges).toBe(false);
    });

    it('does not provide Vibe status badge slots when Atlas disables them', () => {
        const wrapper = mount(TabContentV2View, {
            props: createProps(),
            global: {
                stubs: {
                    BrowseV2StatusBar: testStub,
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
                    BrowseV2StatusBar: testStub,
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

        expect(props.updateActiveIndex).toHaveBeenCalledWith(7);
        expect(props.updateSurfaceMode).toHaveBeenCalledWith('list');
        expect(props.handleAssetLoads).toHaveBeenCalledWith([]);
        expect(props.handleAssetErrors).toHaveBeenCalledWith([]);
    });
});
