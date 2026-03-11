import { type Ref } from 'vue';
import type { FeedItem, TabData } from './useTabs';
import { queueReaction } from '@/utils/reactionQueue';
import type { ReactionType } from '@/types/reaction';
import { Masonry } from '@wyxos/vibe';

type UseMasonryReactionHandlerOptions = {
    items: Ref<FeedItem[]>;
    masonry: Ref<InstanceType<typeof Masonry> | null>;
    tab: Ref<TabData | undefined>;
    isLocal: Readonly<Ref<boolean>>;
    onReaction: (fileId: number, type: ReactionType) => void;
};

/**
 * Composable for handling masonry item reactions with restore functionality.
 */
export function useMasonryReactionHandler(
    options: UseMasonryReactionHandlerOptions,
) {
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
        const tabId = options.tab.value?.id;

        // Only remove from masonry in online mode (not in local mode)
        // Pass item directly - Vibe tracks items by object reference, so we must use the exact reference
        if (!options.isLocal.value) {
            options.masonry.value?.remove(item);
        }

        // Create restore callback for undo functionality (only in online mode where items are removed)
        const restoreCallback = !options.isLocal.value && item && tabId !== undefined
            ? async () => {
                await options.masonry.value?.restore(item);
            }
            : undefined;

        // Queue reaction with countdown toast (pass thumbnail, restore callback, and items for local mode updates)
        const thumbnail = item.preview;
        queueReaction(fileId, type, thumbnail, restoreCallback, options.items, {
            allowRedownloadPrompt: options.isLocal.value,
            updateLocalState: options.isLocal.value,
        });

        // Emit to parent (reaction is queued, not executed yet)
        options.onReaction(fileId, type);
    }

    return {
        handleMasonryReaction,
    };
}
