import { type Ref, triggerRef } from 'vue';
import type { FeedItem } from '@/composables/useTabs';

/**
 * Update the in-memory reaction on an already-loaded item.
 * Callers own the mode check and should only use this for local-mode updates.
 */
export default function updateReactionState(
    items: Ref<FeedItem[]>,
    fileId: number,
    reactionType: string
): void {
    const itemIndex = items.value.findIndex((i) => i.id === fileId);
    if (itemIndex === -1) {
        return;
    }

    const item = items.value[itemIndex];
    item.reaction = { type: reactionType };

    triggerRef(items);
}
