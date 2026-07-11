import { flushPromises, mount } from '@vue/test-utils';
import { defineComponent, h, ref, shallowRef } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useLocalFileDeletion } from '@/composables/useLocalFileDeletion';
import type { FeedItem } from '@/composables/useTabs';
import type { FileContainer } from '@/types/file';
import TabContentV2View from './TabContentV2View.vue';

const EmptyStub = defineComponent({
    name: 'EmptyStub',
    render: () => h('div'),
});

const VibeLayoutStub = defineComponent({
    props: { surfaceMode: { type: String, default: 'list' } },
    setup(props, { slots }) {
        return () => h('div', props.surfaceMode === 'fullscreen'
            ? (slots['fullscreen-aside']?.({ nextPreviews: [], total: 1 }) ?? [])
            : []);
    },
});

const baseStubs = {
    BrowseGlobalStartPanel: EmptyStub,
    BrowseV2StatusBar: EmptyStub,
    ContainerBlacklistManager: EmptyStub,
    DownloadedReactionDialog: EmptyStub,
    LoadedItemsBatchActionDialog: EmptyStub,
    LoadedItemsRemovalDialog: EmptyStub,
    Sheet: EmptyStub,
    SheetContent: EmptyStub,
    TabContentContainerDrawer: EmptyStub,
    TabContentContainerSheet: EmptyStub,
    TabContentServiceHeader: EmptyStub,
    TabContentStartForm: EmptyStub,
    VibeLayout: VibeLayoutStub,
};

function createDeleteFileViewerSheetStub(fileId: number) {
    return defineComponent({
        inheritAttrs: false,
        emits: ['delete-file'],
        setup(_, { emit }) {
            return () => h('button', {
                'data-test': 'request-file-delete',
                onClick: () => emit('delete-file', fileId),
            });
        },
    });
}

const DeleteDialogStub = defineComponent({
    props: { open: Boolean },
    emits: ['confirm', 'update:open'],
    setup(dialogProps, { emit }) {
        return () => dialogProps.open
            ? h('div', [
                h('button', { 'data-test': 'confirm-file-delete', onClick: () => emit('confirm') }),
                h('button', { 'data-test': 'dismiss-file-delete', onClick: () => emit('update:open', false) }),
            ])
            : null;
    },
});

function createItem(): FeedItem {
    return {
        id: 42,
        width: 100,
        height: 100,
        page: 1,
        key: 'file-42',
        index: 0,
        src: '/files/42.jpg',
        filename: 'managed-file.jpg',
        downloaded: true,
    };
}

function createProps(item: FeedItem, localFileDeletion: ReturnType<typeof useLocalFileDeletion>) {
    return {
        activeIndex: 0,
        availableServices: [],
        availableSources: [],
        applyFilters: vi.fn(async () => undefined),
        applyService: vi.fn(async () => undefined),
        cancelFill: vi.fn(),
        closeGridFileSheet: vi.fn(),
        closeViewerFileSheet: vi.fn(),
        containerInteractions: {
            managerRef: ref(null),
            isBlacklistable: vi.fn(() => false),
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
        },
        currentVisibleItem: item,
        downloadedReactionPrompt: {
            data: { open: ref(false) },
            chooseReact: vi.fn(),
            chooseRedownload: vi.fn(),
            close: vi.fn(),
            setOpen: vi.fn(),
        },
        gridFileSheetState: { isOpen: true },
        gridFileSheetItem: item,
        viewerFileSheetState: { isOpen: false },
        viewerFileSheetItem: null,
        fileViewerData: {
            fileData: ref<Record<string, unknown> | null>({ id: item.id }),
            isLoadingFileData: ref(false),
            setFileData: vi.fn(),
        },
        form: { data: { limit: 20, feed: 'online', source: null }, reset: vi.fn() },
        goToFirstPage: vi.fn(async () => undefined),
        handleAssetErrors: vi.fn(),
        handleAssetLoads: vi.fn(),
        handleContainerBlacklistChange: vi.fn(),
        handleItemsChange: vi.fn(),
        handleReaction: vi.fn(),
        headerMasonry: null,
        isFilterSheetOpen: false,
        itemInteractions: {
            reactions: { onFileBlacklist: vi.fn() },
        },
        localFileDeletion,
        localService: null,
        loadNext: vi.fn(),
        masonryRenderKey: 0,
        mouseShortcuts: {
            handleAuxClickCapture: vi.fn(),
            handleClickCapture: vi.fn(),
            handleContextMenuCapture: vi.fn(),
            handleMouseDownCapture: vi.fn(),
        },
        openGridFileSheetForItem: vi.fn(),
        openViewerFileSheet: vi.fn(),
        promptDialog: {
            data: {
                promptDialogItemId: ref(null),
                promptDataLoading: ref(false),
                currentPromptData: ref(null),
            },
        },
        resolve: vi.fn(async () => ({ items: [], nextCursor: null, previousCursor: null })),
        setFilterSheetOpen: vi.fn(),
        setLocalMode: vi.fn(),
        setVibeHandle: vi.fn(),
        shouldShowForm: false,
        surfaceMode: 'list' as 'fullscreen' | 'list',
        tab: { id: 7, label: 'Browse', params: {}, position: 0, isActive: true, updatedAt: null },
        totalAvailable: 1,
        updateFeed: vi.fn(),
        updateActiveIndex: vi.fn(),
        updateSource: vi.fn(),
        updateSurfaceMode: vi.fn(),
        updateService: vi.fn(),
        vibeInitialCursor: null,
        vibeInitialState: undefined,
        vibeStatus: {
            activeIndex: 0,
            hasNextPage: false,
            itemCount: 1,
            pageLoadingLocked: false,
            phase: 'idle',
        },
        viewerKey: 'viewer-actions-test',
    };
}

let originalAxios: typeof window.axios;

beforeEach(() => {
    originalAxios = window.axios;
});

afterEach(() => {
    window.axios = originalAxios;
});

describe('TabContentV2View FileSheet actions', () => {
    it('defers deletion until confirmation and closes only the grid-owned sheet', async () => {
        const item = createItem();
        const deleteRequest = vi.fn().mockResolvedValue({ data: { message: 'deleted' } });
        window.axios = { delete: deleteRequest } as never;
        const localFileDeletion = useLocalFileDeletion({
            items: shallowRef([item]),
            masonry: ref(null),
            isLocal: ref(false),
            clearHover: vi.fn(),
        });
        const props = createProps(item, localFileDeletion);
        const wrapper = mount(TabContentV2View, {
            props: props as never,
            global: { stubs: { ...baseStubs, FileViewerSheet: createDeleteFileViewerSheetStub(item.id), LocalFileDeleteDialog: DeleteDialogStub } },
        });

        await wrapper.get('[data-test="request-file-delete"]').trigger('click');

        expect(localFileDeletion.state.dialogOpen.value).toBe(true);
        expect(deleteRequest).not.toHaveBeenCalled();

        await wrapper.get('[data-test="confirm-file-delete"]').trigger('click');
        await flushPromises();

        expect(deleteRequest).toHaveBeenCalledWith('/api/files/42', {
            data: { also_from_disk: true, also_delete_record: true },
        });
        expect(props.closeGridFileSheet).toHaveBeenCalledTimes(1);
        expect(props.closeViewerFileSheet).not.toHaveBeenCalled();
    });

    it('retains the viewer owner when dismissal is refused during a failed request and closes it after retry', async () => {
        const item = createItem();
        let rejectFirstRequest: ((reason?: unknown) => void) | null = null;
        const firstRequest = new Promise((_, reject) => {
            rejectFirstRequest = reject;
        });
        const deleteRequest = vi.fn()
            .mockReturnValueOnce(firstRequest)
            .mockResolvedValueOnce({ data: { message: 'deleted' } });
        window.axios = { delete: deleteRequest } as never;
        const localFileDeletion = useLocalFileDeletion({
            items: shallowRef([item]),
            masonry: ref(null),
            isLocal: ref(false),
            clearHover: vi.fn(),
        });
        const props = createProps(item, localFileDeletion);
        props.surfaceMode = 'fullscreen';
        props.gridFileSheetState.isOpen = false;
        props.gridFileSheetItem = null;
        props.viewerFileSheetState.isOpen = true;
        props.viewerFileSheetItem = item;
        const wrapper = mount(TabContentV2View, {
            props: props as never,
            global: { stubs: { ...baseStubs, FileViewerSheet: createDeleteFileViewerSheetStub(item.id), LocalFileDeleteDialog: DeleteDialogStub } },
        });

        await wrapper.get('[data-test="request-file-delete"]').trigger('click');
        await wrapper.get('[data-test="confirm-file-delete"]').trigger('click');

        expect(localFileDeletion.state.deleting.value).toBe(true);

        await wrapper.get('[data-test="dismiss-file-delete"]').trigger('click');

        expect(localFileDeletion.state.dialogOpen.value).toBe(true);
        rejectFirstRequest?.(new Error('temporary failure'));
        await flushPromises();

        expect(localFileDeletion.state.deleting.value).toBe(false);
        expect(localFileDeletion.state.dialogOpen.value).toBe(true);
        expect(localFileDeletion.state.deleteError.value).toBe('Failed to delete the file. Please try again.');

        await wrapper.get('[data-test="confirm-file-delete"]').trigger('click');
        await flushPromises();

        expect(deleteRequest).toHaveBeenCalledTimes(2);
        expect(props.closeViewerFileSheet).toHaveBeenCalledTimes(1);
        expect(props.closeGridFileSheet).not.toHaveBeenCalled();
    });

    it('opens the shared blacklist dialog for eligible and already-blacklisted containers', async () => {
        const item = createItem();
        const localFileDeletion = useLocalFileDeletion({
            items: shallowRef([item]),
            masonry: ref(null),
            isLocal: ref(false),
            clearHover: vi.fn(),
        });
        const props = createProps(item, localFileDeletion);
        const openBlacklistDialog = vi.fn();
        const eligible = {
            id: 261,
            type: 'User',
            source: 'CivitAI',
            source_id: 'Tommu',
            blacklisted: false,
        } as FileContainer;
        const blacklisted = {
            id: 262,
            type: 'User',
            source: 'CivitAI',
            source_id: null,
            blacklisted: true,
        } as FileContainer;
        props.containerInteractions.managerRef.value = { openBlacklistDialog };
        props.containerInteractions.isBlacklistable = vi.fn((container: FileContainer) => container.id === eligible.id);
        props.fileViewerData.fileData.value = { id: item.id, containers: [eligible, blacklisted] };
        const fileViewerSheetStub = defineComponent({
            props: {
                canManageContainerBlacklist: { type: Function, required: true },
                fileData: { type: Object, default: null },
            },
            emits: ['manage-container-blacklist'],
            setup(sheetProps, { emit }) {
                return () => h('div', (sheetProps.fileData as { containers: FileContainer[] }).containers
                    .filter((container) => (sheetProps.canManageContainerBlacklist as (value: FileContainer) => boolean)(container))
                    .map((container) => h('button', {
                        'data-test': `manage-container-${container.id}`,
                        onClick: () => emit('manage-container-blacklist', container),
                    })));
            },
        });
        const wrapper = mount(TabContentV2View, {
            props: props as never,
            global: { stubs: { ...baseStubs, FileViewerSheet: fileViewerSheetStub, LocalFileDeleteDialog: EmptyStub } },
        });

        await wrapper.get('[data-test="manage-container-261"]').trigger('click');
        await wrapper.get('[data-test="manage-container-262"]').trigger('click');

        expect(openBlacklistDialog).toHaveBeenNthCalledWith(1, expect.objectContaining({
            id: 261,
            source_id: 'Tommu',
            currentFileIds: [42],
        }));
        expect(openBlacklistDialog).toHaveBeenNthCalledWith(2, expect.objectContaining({
            id: 262,
            source_id: '',
            currentFileIds: [42],
        }));
    });
});
