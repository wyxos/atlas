import { ref, watch, type Ref } from 'vue';
import type { FeedItem } from '@/composables/useTabs';

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
    const preloadedUrls = ref<Set<string>>(new Set());
    const preloadingUrls = ref<Set<string>>(new Set());

    function preloadImage(url: string): Promise<void> {
        if (preloadedUrls.value.has(url) || preloadingUrls.value.has(url)) {
            return Promise.resolve();
        }

        preloadingUrls.value.add(url);

        return new Promise((resolve) => {
            const img = new Image();
            img.onload = () => {
                preloadedUrls.value.add(url);
                preloadingUrls.value.delete(url);
                resolve();
            };
            img.onerror = () => {
                preloadingUrls.value.delete(url);
                resolve(); // Don't reject, just skip failed preloads
            };
            img.src = url;
        });
    }

    function preloadVideo(url: string): Promise<void> {
        if (preloadedUrls.value.has(url) || preloadingUrls.value.has(url)) {
            return Promise.resolve();
        }

        preloadingUrls.value.add(url);

        return new Promise((resolve) => {
            const video = document.createElement('video');
            video.preload = 'metadata';

            video.onloadedmetadata = () => {
                preloadedUrls.value.add(url);
                preloadingUrls.value.delete(url);
                resolve();
            };
            video.onerror = () => {
                preloadingUrls.value.delete(url);
                resolve();
            };

            // Start loading but don't add to DOM
            video.src = url;
            video.load();
        });
    }

    function preloadItem(item: FeedItem): void {
        const mimeType = typeof item.mime_type === 'string' ? item.mime_type : null;
        const isVideo = mimeType?.startsWith('video/') ||
                        item.preview?.includes('/video/') ||
                        item.original?.includes('/video/');

        if (isVideo) {
            // Preload video poster (preview image) and optionally video metadata
            if (item.preview) {
                void preloadImage(item.preview);
            }
            if (item.original) {
                void preloadVideo(item.original);
            }
        } else {
            // Preload full-size image
            if (item.original) {
                void preloadImage(item.original);
            } else if (item.preview) {
                void preloadImage(item.preview);
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
        preloadedUrls.value.clear();
        preloadingUrls.value.clear();
    }

    return {
        preloadedUrls,
        preloadNextItems,
        clearPreloadCache,
    };
}
