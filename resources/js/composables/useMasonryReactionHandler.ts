import { nextTick, type Ref } from 'vue';
import type { MasonryItem, TabData } from './useTabs';
import { queueReaction } from '@/utils/reactionQueue';
import type { ReactionType } from '@/types/reaction';
import type { Masonry } from '@wyxos/vibe';

/**
 * Composable for handling masonry item reactions with restore functionality.
 */
export function useMasonryReactionHandler(
    items: Ref<MasonryItem[]>,
    itemsMap: Ref<Map<number, MasonryItem>>,
    masonry: Ref<InstanceType<typeof Masonry> | null>,
    tab: Ref<TabData | undefined>,
    onReaction: (fileId: number, type: ReactionType) => void,
    restoreToMasonry: (item: MasonryItem, index: number, masonryInstance?: InstanceType<typeof Masonry>) => Promise<void>
) {

    /**
     * Handle reaction - removes item from masonry and queues reaction.
     */
    async function handleMasonryReaction(
        fileId: number,
        type: ReactionType
    ): Promise<void> {
        // Use Map lookup instead of O(n) find operations
        const item = itemsMap.value.get(fileId);
        const itemIndex = item ? items.value.findIndex((i) => i.id === fileId) : -1;
        const tabId = tab.value?.id;

        // Remove from masonry BEFORE queueing
        if (item && masonry.value?.remove) {
            masonry.value.remove(item);
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

