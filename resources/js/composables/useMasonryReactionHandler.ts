import { type Ref } from 'vue';
import type { FeedItem, TabData } from './useTabs';
import { queueReaction } from '@/utils/reactionQueue';
import type { ReactionType } from '@/types/reaction';
import { Masonry } from '@wyxos/vibe';
import { useBrowseForm } from './useBrowseForm';

/**
 * Composable for handling masonry item reactions with restore functionality.
 */
export function useMasonryReactionHandler(
    items: Ref<FeedItem[]>,
    masonry: Ref<InstanceType<typeof Masonry> | null>,
    tab: Ref<TabData | undefined>,
    onReaction: (fileId: number, type: ReactionType) => void
) {
    const { isLocal } = useBrowseForm();

    /**
     * Handle reaction - conditionally removes item from masonry based on feed mode and queues reaction.
     * In local mode: items are NOT removed (visual treatment only).
     * In online mode: items are removed immediately.
     */
    async function handleMasonryReaction(
        item: FeedItem,
        type: ReactionType,
        _index?: number
    ): Promise<void> {
        const fileId = item.id;
        const tabId = tab.value?.id;
        const shouldLoadNext = !isLocal.value && items.value.length <= 1;

        // Only remove from masonry in online mode (not in local mode)
        // Pass item directly - Vibe tracks items by object reference, so we must use the exact reference
        if (!isLocal.value) {
            await masonry.value?.remove(item);
            if (shouldLoadNext && !masonry.value?.isLoading) {
                await masonry.value?.loadNextPage?.();
            }
        }

        // Create restore callback for undo functionality (only in online mode where items are removed)
        const restoreCallback = !isLocal.value && item && tabId !== undefined
            ? async () => {
                await masonry.value?.restore(item);
            }
            : undefined;

        // Queue reaction with countdown toast (pass thumbnail, restore callback, and items for local mode updates)
        const thumbnail = item.preview;
        queueReaction(fileId, type, thumbnail, restoreCallback, items);

        // Emit to parent (reaction is queued, not executed yet)
        onReaction(fileId, type);
    }

    return {
        handleMasonryReaction,
    };
}
