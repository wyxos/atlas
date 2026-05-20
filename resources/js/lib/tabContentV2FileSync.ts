import { computed, type ComputedRef, type Ref } from 'vue';
import type { VibeInitialState, VibeStatus } from '@wyxos/vibe';
import type { FeedItem } from '@/composables/useTabs';
import type { BrowsePageToken } from '@/types/browse';
import type { File } from '@/types/file';
import { mapFeedItemToVibeItem, normalizeCursor } from './tabContentV2';

type FileViewerDataSync = {
    fileData: Ref<File | null>;
    isLoadingFileData: Ref<boolean>;
    setFileData: (file: File) => void;
};

export function createSyncedFileViewerData(args: {
    activeIndex: Ref<number>;
    fallbackItems: ComputedRef<FeedItem[]>;
    fileViewerData: FileViewerDataSync;
    getCurrentItems: () => FeedItem[];
    hydratedInitialState: Ref<VibeInitialState | undefined>;
    masonryRenderKey: Ref<number>;
    startPageToken: Ref<BrowsePageToken>;
    vibeStatus: ComputedRef<VibeStatus>;
}) {
    function refreshVibeInitialStateFromCurrentItems(): void {
        const currentItems = args.getCurrentItems();
        const nextItems = currentItems.length > 0 ? currentItems : args.fallbackItems.value;

        args.hydratedInitialState.value = {
            items: nextItems.map(mapFeedItemToVibeItem),
            cursor: normalizeCursor(args.vibeStatus.value.currentCursor ?? args.startPageToken.value),
            nextCursor: normalizeCursor(args.vibeStatus.value.nextCursor),
            previousCursor: normalizeCursor(args.vibeStatus.value.previousCursor),
            activeIndex: args.activeIndex.value,
        };
        args.masonryRenderKey.value += 1;
    }

    return computed(() => ({
        fileData: args.fileViewerData.fileData,
        isLoadingFileData: args.fileViewerData.isLoadingFileData,
        setFileData: (file: File): void => {
            args.fileViewerData.setFileData(file);
            refreshVibeInitialStateFromCurrentItems();
        },
    }));
}
