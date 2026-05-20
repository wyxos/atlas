import { computed, type Ref } from 'vue';
import type { VibeViewerItem } from '@wyxos/vibe';
import type { FeedItem } from '@/composables/useTabs';
import type { File } from '@/types/file';
import { mapFeedItemToVibeItem } from './tabContentV2';
import { getFeedItemFromVibeItem } from './tabContentV2VibeItems';

type FileViewerDataSync = {
    fileData: Ref<File | null>;
    isLoadingFileData: Ref<boolean>;
    setFileData: (file: File) => void;
};

function getVibeFileId(item: VibeViewerItem): number | null {
    const feedItemId = getFeedItemFromVibeItem(item)?.id;
    if (typeof feedItemId === 'number') {
        return feedItemId;
    }

    const fileId = item.fileId;
    if (typeof fileId === 'number') {
        return fileId;
    }

    if (typeof fileId === 'string') {
        const parsed = Number(fileId);
        return Number.isFinite(parsed) ? parsed : null;
    }

    const parsed = Number(item.id);
    return Number.isFinite(parsed) ? parsed : null;
}

function patchVibeItem(vibeItem: VibeViewerItem, feedItem: FeedItem): void {
    const mapped = mapFeedItemToVibeItem(feedItem);

    vibeItem.id = mapped.id;
    vibeItem.type = mapped.type;
    vibeItem.title = mapped.title;
    vibeItem.url = mapped.url;
    vibeItem.preview = mapped.preview;
    vibeItem.healthCheck = mapped.healthCheck;
    vibeItem.width = mapped.width;
    vibeItem.height = mapped.height;
    vibeItem.feedItem = feedItem;
    vibeItem.fileId = feedItem.id;
    vibeItem.page = feedItem.page;
    vibeItem.key = feedItem.key;
}

export function createSyncedFileViewerData(args: {
    fileViewerData: FileViewerDataSync;
    getCurrentVibeItems: () => VibeViewerItem[];
}) {
    function syncVibeItem(file: File): void {
        for (const vibeItem of args.getCurrentVibeItems()) {
            if (getVibeFileId(vibeItem) !== file.id) {
                continue;
            }

            const feedItem = getFeedItemFromVibeItem(vibeItem);
            if (feedItem) {
                patchVibeItem(vibeItem, feedItem);
            }
        }
    }

    return computed(() => ({
        fileData: args.fileViewerData.fileData,
        isLoadingFileData: args.fileViewerData.isLoadingFileData,
        setFileData: (file: File): void => {
            args.fileViewerData.setFileData(file);
            syncVibeItem(file);
        },
    }));
}
