import { nextTick, type Ref } from 'vue';
import type { MasonryItem, BrowseTabData } from './useBrowseTabs';
import { queueReaction } from '@/utils/reactionQueue';
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

    /**
     * Handle reaction (wrapper for masonry removeItem callback).
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

        // Remove from masonry BEFORE queueing
        if (item && removeItem) {
            removeItem(item);
        }

        // Create restore callback for undo functionality
        const restoreCallback = item && tabId !== undefined && itemIndex !== -1
            ? async () => {
                // Restore item using the provided restore function
                await restoreToMasonry(item, itemIndex, masonry.value);
            }
            : undefined;

        // Queue reaction with countdown toast (pass thumbnail and restore callback)
        const thumbnail = item?.thumbnail || item?.src || '';
        queueReaction(fileId, type, thumbnail, restoreCallback);

        // Emit to parent (reaction is queued, not executed yet)
        onReaction(fileId, type);
    }

    return {
        handleMasonryReaction,
    };
}

