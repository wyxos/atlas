import { nextTick, type Ref } from 'vue';
import type { MasonryItem, BrowseTabData } from './useBrowseTabs';
import { useReactionQueue } from './useReactionQueue';
import { createReactionCallback } from '@/utils/reactions';
import type { ReactionType } from '@/types/reaction';

/**
 * Composable for handling masonry item reactions with restore functionality.
 */
export function useMasonryReactionHandler(
    items: Ref<MasonryItem[]>,
    itemsMap: Ref<Map<number, MasonryItem>>,
    masonry: Ref<any>,
    tab: Ref<BrowseTabData | undefined>,
    onReaction: (fileId: number, type: ReactionType) => void,
    restoreToMasonry: (item: MasonryItem, index: number, masonryInstance?: any) => Promise<void>
) {
    const { queueReaction } = useReactionQueue();

    /**
     * Handle reaction with queue (wrapper for masonry removeItem callback).
     */
    async function handleMasonryReaction(
        fileId: number,
        type: ReactionType,
        removeItem: (item: MasonryItem) => void
    ): Promise<void> {
        // Use Map lookup instead of O(n) find operations
        const item = itemsMap.value.get(fileId);
        const itemIndex = item ? items.value.findIndex((i) => i.id === fileId) : -1;
        const tabId = tab.value?.id;

        // Create restore callback to add item back to masonry at original index
        const restoreItem = item && tabId !== undefined && itemIndex !== -1
            ? async (restoreTabId: number, isTabActive: (tabId: number) => boolean) => {
                // Only restore if the tab is active
                const tabActive = isTabActive(restoreTabId);
                if (!tabActive) {
                    return;
                }

                // Restore item using the provided restore function
                await restoreToMasonry(item, itemIndex, masonry.value);
            }
            : undefined;

        if (item && removeItem) {
            removeItem(item);
        }

        // Remove auto_disliked and will_auto_dislike flags if user is reacting (like, funny, favorite - not dislike)
        if (item && (type === 'love' || type === 'like' || type === 'funny')) {
            // itemIndex already calculated above, reuse it
            if (itemIndex !== -1) {
                Object.assign(items.value[itemIndex], {
                    auto_disliked: false,
                    will_auto_dislike: false,
                });
            }
            // Also update in tab.itemsData if it exists
            if (tab.value?.itemsData) {
                const tabItemIndex = tab.value.itemsData.findIndex((i) => i.id === fileId);
                if (tabItemIndex !== -1) {
                    Object.assign(tab.value.itemsData[tabItemIndex], {
                        auto_disliked: false,
                        will_auto_dislike: false,
                    });
                }
            }
            await nextTick();
        }

        // Queue the AJAX request with restore callback, tab ID, index, and item
        const previewUrl = item?.src;
        queueReaction(fileId, type, createReactionCallback(), previewUrl, restoreItem, tabId, itemIndex, item);

        // Emit to parent
        onReaction(fileId, type);
    }

    return {
        handleMasonryReaction,
    };
}

