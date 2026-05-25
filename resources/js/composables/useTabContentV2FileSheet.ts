import { computed, ref, type ComputedRef, type Ref } from 'vue';
import { useFileViewerSheetState } from '@/composables/useFileViewerSheetState';
import type { FeedItem } from '@/composables/useTabs';

type FileSheetPresentation = 'inline' | 'overlay';
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
}) {
    const sheet = useFileViewerSheetState({ overlay: params.overlay });
    const pinnedItem = ref<FeedItem | null>(null);
    const presentation = ref<FileSheetPresentation>('inline');

    const item = computed(() => (
        presentation.value === 'overlay'
            ? (pinnedItem.value ?? params.currentVisibleItem.value)
            : params.currentVisibleItem.value
    ));
    const targetFileId = computed(() => item.value?.id ?? null);

    function open(): void {
        pinnedItem.value = null;
        presentation.value = 'inline';
        params.promptDialog.clear();
        sheet.setSheetOpen(true);
    }

    function openForItem(fileItem: FeedItem, index: number): void {
        params.activeIndex.value = index;
        pinnedItem.value = fileItem;
        presentation.value = 'overlay';
        params.promptDialog.select(fileItem);
        sheet.setSheetOpen(true);
    }

    function close(): void {
        sheet.setSheetOpen(false);
        pinnedItem.value = null;
        params.promptDialog.clear();
    }

    function closeForFullscreenExit(): void {
        sheet.setSheetOpen(false, { persist: false });
        pinnedItem.value = null;
        presentation.value = 'inline';
        params.promptDialog.clear();
    }

    function reset(): void {
        closeForFullscreenExit();
    }

    return {
        close,
        closeForFullscreenExit,
        item,
        open,
        openForItem,
        presentation,
        reset,
        state: sheet.sheetState,
        targetFileId,
    };
}
