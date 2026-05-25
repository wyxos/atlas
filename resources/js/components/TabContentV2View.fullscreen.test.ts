import { mount } from '@vue/test-utils';
import { defineComponent, h, ref } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import TabContentV2View from './TabContentV2View.vue';

const testState = vi.hoisted(() => ({
    item: {
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

vi.mock('@wyxos/vibe', () => ({
    VibeLayout: defineComponent({
        name: 'VibeLayout',
        props: { activeIndex: { type: Number, default: 0 } },
        setup(props, { slots }) {
            return () => h('div', { 'data-testid': 'vibe-layout' }, [
                h('div', { 'data-testid': 'mock-vibe-header' }, slots['fullscreen-header-actions']?.({
                    index: props.activeIndex,
                    item: testState.item,
                    total: 20,
                })),
                h('div', { 'data-testid': 'mock-vibe-footer' }, slots['fullscreen-footer']?.({
                    index: props.activeIndex,
                    item: testState.item,
                    nextPreviews: [],
                    total: 20,
                })),
                h('div', { 'data-testid': 'mock-vibe-aside' }, slots['fullscreen-aside']?.({
                    index: props.activeIndex,
                    item: testState.item,
                    nextPreviews: [{ index: 2, item: testState.item, asset: { kind: 'image', label: 'Next', url: '/next.jpg', width: 100, height: 100 } }],
                    total: 20,
                })),
            ]);
        },
    }),
}));

const testStub = defineComponent({
    name: 'TestStub',
    render() {
        return h('div');
    },
});

const defaultStubs = {
    BrowseV2StatusBar: testStub,
    Button: testStub,
    ContainerBlacklistManager: testStub,
    DownloadedReactionDialog: testStub,
    FileViewerSheet: testStub,
    LocalFileDeleteDialog: testStub,
    PanelRightOpen: testStub,
    TabContentContainerDrawer: testStub,
    TabContentContainerSheet: testStub,
    TabContentPromptDialog: testStub,
    TabContentServiceHeader: testStub,
    TabContentStartForm: testStub,
    TabContentV2GridOverlay: testStub,
};

function createVibeStatus() {
    return {
        activeIndex: 1,
        currentCursor: '1',
        errorMessage: null,
        fillCollectedCount: null,
        fillCompletedCalls: 0,
        fillDelayRemainingMs: null,
        fillLoadedCount: 0,
        fillMode: 'idle',
        fillProgress: null,
        fillTargetCalls: null,
        fillTargetCount: null,
        fillTotalCount: null,
        hasNextPage: true,
        hasPreviousPage: false,
        itemCount: 20,
        itemsRevision: 0,
        loadState: 'loaded',
        nextBoundaryLoadProgress: 0,
        nextCursor: '2',
        pageLoadingLocked: false,
        phase: 'idle',
        previousBoundaryLoadProgress: 0,
        previousCursor: null,
        removedCount: 0,
        removedIds: [],
        removedRevision: 0,
        surfaceMode: 'fullscreen',
    };
}

function createContainerInteractions() {
    return {
        managerRef: ref(null),
        badges: {
            getContainersForItem: vi.fn().mockReturnValue([]),
            getItemCountForContainerId: vi.fn().mockReturnValue(0),
            getVariantForContainerType: vi.fn().mockReturnValue('default'),
        },
        pillHandlers: {
            onMouseEnter: vi.fn(),
            onMouseLeave: vi.fn(),
            onClick: vi.fn(),
            onDoubleClick: vi.fn(),
            onContextMenu: vi.fn(),
            onMouseDown: vi.fn(),
            onAuxClick: vi.fn(),
            onDismiss: vi.fn(),
        },
        isBlacklistable: vi.fn().mockReturnValue(false),
        drawer: {
            state: { isOpen: ref(false) },
            derived: { container: ref(null), highlightedItemIds: ref(new Set<number>()), items: ref([]) },
            actions: { setOpen: vi.fn(), syncHoverTarget: vi.fn() },
        },
        sheet: {
            state: { isOpen: ref(false) },
            derived: { container: ref(null), items: ref([]) },
            actions: { close: vi.fn() },
        },
    };
}

function createProps() {
    return {
        activeIndex: 1,
        availableServices: [],
        availableSources: [],
        applyFilters: vi.fn(),
        applyService: vi.fn(),
        cancelFill: vi.fn(),
        closeFileSheet: vi.fn(),
        containerInteractions: createContainerInteractions(),
        currentVisibleItem: null,
        downloadedReactionPrompt: { data: { open: ref(false) }, chooseReact: vi.fn(), chooseRedownload: vi.fn(), close: vi.fn(), setOpen: vi.fn() },
        fileSheetState: { isOpen: false },
        fileSheetItem: null,
        fileSheetPresentation: 'inline' as 'inline' | 'overlay',
        fileViewerData: { fileData: ref(null), isLoadingFileData: ref(false), setFileData: vi.fn() },
        form: { data: { limit: 20, feed: 'online', source: null }, reset: vi.fn() },
        goToFirstPage: vi.fn(),
        handleAssetErrors: vi.fn(),
        handleAssetLoads: vi.fn(),
        handleContainerBlacklistChange: vi.fn(),
        handleItemsChange: vi.fn(),
        handleReaction: vi.fn(),
        headerMasonry: null,
        isFilterSheetOpen: false,
        itemInteractions: { performLoadedItemsBulkAction: vi.fn(), reactions: { onFileBlacklist: vi.fn() } },
        localFileDeletion: { state: { dialogOpen: ref(false), itemToDelete: ref(null), deleting: ref(false), deleteError: ref(null) }, actions: { close: vi.fn(), confirm: vi.fn() } },
        localService: null,
        loadNext: vi.fn(),
        masonryRenderKey: 0,
        mouseShortcuts: { handleAuxClickCapture: vi.fn(), handleClickCapture: vi.fn(), handleContextMenuCapture: vi.fn(), handleMouseDownCapture: vi.fn() },
        openFileSheet: vi.fn(),
        openFileSheetForItem: vi.fn(),
        promptDialog: { data: { promptDialogOpen: ref(false), promptDialogItemId: ref(null), promptDataLoading: ref(false), currentPromptData: ref(null) }, clear: vi.fn(), setOpen: vi.fn(), select: vi.fn(), copy: vi.fn(), openTestPage: vi.fn(), close: vi.fn() },
        resolve: vi.fn(),
        setFilterSheetOpen: vi.fn(),
        setLocalMode: vi.fn(),
        setVibeHandle: vi.fn(),
        shouldShowForm: false,
        surfaceMode: 'fullscreen' as const,
        tab: { id: 7, label: 'Browse Tab', params: {}, position: 0, isActive: true, updatedAt: null },
        totalAvailable: null,
        updateFeed: vi.fn(),
        updateActiveIndex: vi.fn(),
        updateSource: vi.fn(),
        updateSurfaceMode: vi.fn(),
        updateService: vi.fn(),
        vibeInitialCursor: '1',
        vibeInitialState: undefined,
        vibeStatus: createVibeStatus(),
        viewerKey: 'viewer-key',
    };
}

describe('TabContentV2View fullscreen chrome', () => {
    beforeEach(() => {
        testState.item = {
            fileId: 11,
            feedItem: { id: 11, previewed_count: 1, reaction: null, seen_count: 0 },
            id: 'overlay-item',
            type: 'image',
        };
    });

    it('renders fullscreen reactions in the Vibe footer for audio and video items', () => {
        for (const type of ['audio', 'video'] as const) {
            testState.item = {
                fileId: type === 'audio' ? 44711 : 2853735,
                feedItem: { id: type === 'audio' ? 44711 : 2853735, previewed_count: 1, reaction: null, seen_count: 0 },
                id: `${type}-overlay-item`,
                type,
            };

            const wrapper = mount(TabContentV2View, { props: createProps(), global: { stubs: defaultStubs } });

            const footer = wrapper.get('[data-testid="mock-vibe-footer"]');
            const reactions = wrapper.get('[data-testid="browse-fullscreen-reactions"]');

            expect(footer.element.contains(reactions.element)).toBe(true);
            expect(reactions.attributes('class')).not.toContain('bottom-');

            wrapper.unmount();
        }
    });

    it('passes fullscreen blacklist state and action to FileReactions', async () => {
        testState.item.feedItem = {
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
            props: { blacklistedAt: { type: String, default: null } },
            setup(stubProps, { emit }) {
                fileReactionsSpy(stubProps);
                return () => h('button', { 'data-testid': 'fullscreen-blacklist-trigger', onClick: () => emit('blacklist') });
            },
        });

        const wrapper = mount(TabContentV2View, { props, global: { stubs: { ...defaultStubs, FileReactions: fileReactionsStub } } });

        expect(fileReactionsSpy).toHaveBeenCalledWith(expect.objectContaining({ blacklistedAt: '2026-04-30T00:00:00Z' }));

        await wrapper.get('[data-testid="fullscreen-blacklist-trigger"]').trigger('click');

        expect(props.itemInteractions.reactions.onFileBlacklist).toHaveBeenCalledWith(testState.item.feedItem);
    });

    it('renders fullscreen container pills with the shared pill interactions', async () => {
        const props = createProps();
        const container = { id: 501, type: 'User', source: 'CivitAI', source_id: 'dougie' };

        props.containerInteractions.badges.getContainersForItem = vi.fn().mockReturnValue([container]);
        props.containerInteractions.badges.getItemCountForContainerId = vi.fn().mockReturnValue(5);
        props.containerInteractions.isBlacklistable = vi.fn().mockReturnValue(true);

        const pillStub = defineComponent({
            name: 'PillStub',
            emits: ['dismiss'],
            props: { label: { type: String, required: true }, value: { type: Number, required: true } },
            setup(stubProps, { emit }) {
                return () => h('button', { 'data-testid': 'fullscreen-container-pill', onClick: () => emit('dismiss') }, `${stubProps.label}:${stubProps.value}`);
            },
        });
        const wrapper = mount(TabContentV2View, { props, global: { stubs: { ...defaultStubs, Pill: pillStub } } });
        const pillRow = wrapper.get('[data-testid="browse-fullscreen-container-pills"]');
        const trigger = wrapper.get('[data-container-pill-trigger]');

        expect(wrapper.get('[data-testid="mock-vibe-header"]').element.contains(pillRow.element)).toBe(true);
        expect(pillRow.attributes('class')).toContain('flex-row');
        expect(pillRow.attributes('class')).toContain('justify-end');
        expect(wrapper.get('[data-testid="fullscreen-container-pill"]').text()).toBe('User:5');

        await trigger.trigger('mouseenter');
        await trigger.trigger('click');
        await trigger.trigger('dblclick');
        await trigger.trigger('contextmenu');
        await trigger.trigger('mousedown', { button: 1 });
        await trigger.trigger('mouseup', { button: 1 });
        await wrapper.get('[data-testid="fullscreen-container-pill"]').trigger('click');

        expect(props.containerInteractions.pillHandlers.onMouseEnter).toHaveBeenCalledWith(container.id);
        expect(props.containerInteractions.pillHandlers.onClick).toHaveBeenCalledWith(container.id, expect.any(MouseEvent));
        expect(props.containerInteractions.pillHandlers.onDoubleClick).toHaveBeenCalledWith(container.id, expect.any(MouseEvent));
        expect(props.containerInteractions.pillHandlers.onContextMenu).toHaveBeenCalledWith(container.id, expect.any(MouseEvent));
        expect(props.containerInteractions.pillHandlers.onMouseDown).toHaveBeenCalledWith(expect.any(MouseEvent));
        expect(props.containerInteractions.pillHandlers.onAuxClick).toHaveBeenCalledWith(container.id, expect.any(MouseEvent));
        expect(props.containerInteractions.pillHandlers.onDismiss).toHaveBeenCalledWith(container);
    });

    it('renders the file sheet in the fullscreen aside slot', () => {
        const props = createProps();
        const sheetSpy = vi.fn();
        props.fileSheetState.isOpen = true;
        props.currentVisibleItem = { id: 11 } as never;
        props.fileSheetItem = props.currentVisibleItem;

        const fileViewerSheetStub = defineComponent({
            name: 'FileViewerSheetStub',
            props: {
                embedded: { type: Boolean, default: false },
                fileId: { type: Number, default: null },
                nextPreviews: { type: Array, default: () => [] },
                totalItems: { type: Number, default: 0 },
            },
            setup(stubProps) {
                sheetSpy(stubProps);

                return () => h('div', { 'data-testid': 'file-viewer-sheet-stub' });
            },
        });

        const wrapper = mount(TabContentV2View, { props, global: { stubs: { ...defaultStubs, FileViewerSheet: fileViewerSheetStub } } });
        const aside = wrapper.get('[data-testid="mock-vibe-aside"]');
        const sheet = wrapper.get('[data-testid="file-viewer-sheet-stub"]');

        expect(aside.element.contains(sheet.element)).toBe(true);
        expect(wrapper.get('[data-testid="mock-vibe-header"]').element.contains(sheet.element)).toBe(false);
        expect(wrapper.get('[data-testid="mock-vibe-footer"]').element.contains(sheet.element)).toBe(false);
        expect(sheetSpy).toHaveBeenCalledWith(expect.objectContaining({
            embedded: true,
            fileId: 11,
            totalItems: 20,
        }));
        expect(sheetSpy.mock.calls[0][0].nextPreviews).toHaveLength(1);
    });

    it('leaves the fullscreen aside empty when the file sheet is closed', () => {
        const fileViewerSheetStub = defineComponent({
            name: 'FileViewerSheetStub',
            setup() {
                return () => h('div', { 'data-testid': 'file-viewer-sheet-stub' });
            },
        });

        const wrapper = mount(TabContentV2View, { props: createProps(), global: { stubs: { ...defaultStubs, FileViewerSheet: fileViewerSheetStub } } });

        expect(wrapper.find('[data-testid="file-viewer-sheet-stub"]').exists()).toBe(false);
    });

    it('wires the fullscreen file sheet toggle', async () => {
        const props = createProps();
        const wrapper = mount(TabContentV2View, { props, global: { stubs: defaultStubs } });

        await wrapper.get('[aria-label="Show file sheet"]').trigger('click');

        expect(props.openFileSheet).toHaveBeenCalledTimes(1);
    });
});
