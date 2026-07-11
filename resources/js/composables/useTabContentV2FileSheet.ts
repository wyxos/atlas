import { computed, reactive, ref, type ComputedRef, type Ref } from 'vue';
import { useFileViewerSheetState } from '@/composables/useFileViewerSheetState';
import type { FeedItem } from '@/composables/useTabs';

type FileSheetOverlay = Parameters<typeof useFileViewerSheetState>[0]['overlay'];
type FileSheetPromptDialog = {
    clear: () => void;
    select: (item: FeedItem) => void;
};

export function useTabContentV2FileSheet(params: {
    activeIndex: Ref<number>;
    currentVisibleItem: ComputedRef<FeedItem | null>;
    overlay: FileSheetOverlay;
    promptDialog: FileSheetPromptDialog;
    surfaceMode: Ref<'fullscreen' | 'list'>;
}) {
    const viewerSheet = useFileViewerSheetState({
        overlay: params.overlay,
        storageKey: 'atlas:fileViewerSheetOpen',
    });
    const gridSheet = useFileViewerSheetState({
        autoOpenForFileMedia: false,
        overlay: params.overlay,
        storageKey: null,
    });
    const gridItem = ref<FeedItem | null>(null);
    const viewerItem = computed(() => params.currentVisibleItem.value);
    const viewerTargetFileId = computed(() => viewerItem.value?.id ?? null);
    const gridTargetFileId = computed(() => gridItem.value?.id ?? null);
    const activeState = reactive({
        get isOpen(): boolean {
            return params.surfaceMode.value === 'fullscreen'
                ? viewerSheet.sheetState.isOpen
                : gridSheet.sheetState.isOpen;
        },
    });
    const activeTargetFileId = computed(() => (
        params.surfaceMode.value === 'fullscreen'
            ? viewerTargetFileId.value
            : gridTargetFileId.value
    ));

    function openViewer(): void {
        params.promptDialog.clear();
        viewerSheet.setSheetOpen(true);
    }

    function openGridForItem(fileItem: FeedItem, index: number): void {
        params.activeIndex.value = index;
        gridItem.value = fileItem;
        params.promptDialog.select(fileItem);
        gridSheet.setSheetOpen(true);
    }

    function closeViewer(): void {
        viewerSheet.setSheetOpen(false);
        params.promptDialog.clear();
    }

    function closeGrid(): void {
        gridSheet.setSheetOpen(false);
        gridItem.value = null;
        params.promptDialog.clear();
    }

    function resetViewer(): void {
        viewerSheet.setSheetOpen(false, { persist: false });
        params.promptDialog.clear();
    }

    function enterViewer(): void {
        if (viewerSheet.sheetState.isOpen) {
            params.promptDialog.clear();
        }
    }

    function exitViewer(): void {
        params.promptDialog.clear();

        if (gridSheet.sheetState.isOpen && gridItem.value) {
            params.promptDialog.select(gridItem.value);
        }
    }

    function reset(): void {
        resetViewer();
        gridSheet.setSheetOpen(false, { persist: false });
        gridItem.value = null;
    }

    return {
        active: {
            state: activeState,
            targetFileId: activeTargetFileId,
        },
        grid: {
            close: closeGrid,
            item: computed(() => gridItem.value),
            openForItem: openGridForItem,
            state: gridSheet.sheetState,
            targetFileId: gridTargetFileId,
        },
        reset,
        viewer: {
            close: closeViewer,
            enter: enterViewer,
            exit: exitViewer,
            item: viewerItem,
            open: openViewer,
            state: viewerSheet.sheetState,
            targetFileId: viewerTargetFileId,
        },
    };
}
