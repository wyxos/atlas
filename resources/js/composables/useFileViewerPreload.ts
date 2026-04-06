import { watch, type Ref } from 'vue';
import type { FeedItem } from '@/composables/useTabs';
import { getMimeTypeCategory } from '@/utils/file';
import {
    clearFileViewerPreloadCache,
    preloadImage,
    preloadVideoMetadata,
} from '@/utils/fileViewer';

interface PreloadOptions {
    items: Ref<FeedItem[]>;
    currentItemIndex: Ref<number | null>;
    fillComplete: Ref<boolean>;
    preloadCount?: number;
}

export function useFileViewerPreload({
    items,
    currentItemIndex,
    fillComplete,
    preloadCount = 2,
}: PreloadOptions) {
    function preloadItem(item: FeedItem): void {
        const mediaKind = typeof item.media_kind === 'string' ? item.media_kind : null;
        const mimeType = typeof item.mime_type === 'string' ? item.mime_type : null;
        const mimeCategory = getMimeTypeCategory(mimeType);

        const isVideo = mediaKind === 'video' ||
            mimeCategory === 'video' ||
            item.preview?.includes('/video/') ||
            item.original?.includes('/video/');

        const isAudio = mediaKind === 'audio' || mimeCategory === 'audio';
        const isFile = mediaKind === 'file';

        if (isVideo) {
            // Preload video poster (preview image) and optionally video metadata
            if (item.preview) {
                void preloadImage(item.preview).catch(() => {});
            }
            if (item.original) {
                void preloadVideoMetadata(item.original);
            }
        } else if (isAudio || isFile) {
            // Keep it light: preload the icon/thumbnail, not the underlying file stream.
            if (item.preview) {
                void preloadImage(item.preview).catch(() => {});
            }
        } else {
            // Preload full-size image
            if (item.original) {
                void preloadImage(item.original).catch(() => {});
            } else if (item.preview) {
                void preloadImage(item.preview).catch(() => {});
            }
        }
    }

    function preloadNextItems(): void {
        const index = currentItemIndex.value;
        if (index === null || !fillComplete.value) {
            return;
        }

        const itemList = items.value;
        const endIndex = Math.min(index + preloadCount + 1, itemList.length);

        for (let i = index + 1; i < endIndex; i++) {
            const item = itemList[i];
            if (item) {
                preloadItem(item);
            }
        }
    }

    // Preload when fill completes (initial open)
    watch(fillComplete, (filled) => {
        if (filled) {
            preloadNextItems();
        }
    });

    // Preload after navigation
    watch(currentItemIndex, (newIndex, oldIndex) => {
        if (newIndex !== null && fillComplete.value && newIndex !== oldIndex) {
            preloadNextItems();
        }
    });

    function clearPreloadCache(): void {
        clearFileViewerPreloadCache();
    }

    return {
        preloadNextItems,
        clearPreloadCache,
    };
}
