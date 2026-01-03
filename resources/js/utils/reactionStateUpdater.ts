import { type Ref, triggerRef } from 'vue';
import type { FeedItem } from '@/composables/useTabs';
import { useBrowseForm } from '@/composables/useBrowseForm';

/**
 * Helper function to update reaction state on an item in local mode.
 * Called by reactionQueue after reaction completes.
 */
export default function updateReactionState(
    items: Ref<FeedItem[]>,
    fileId: number,
    reactionType: string
): void {
    const { isLocal } = useBrowseForm();
    
    if (!isLocal.value) {
        return; // Only update in local mode
    }

    // Find item index
    const itemIndex = items.value.findIndex((i) => i.id === fileId);
    if (itemIndex === -1) {
        return; // Item not found
    }

    // Update the item's reaction property in place (mutate the object)
    // This is efficient as we're only updating one property on one object
    const item = items.value[itemIndex];
    item.reaction = { type: reactionType };

    // Manually trigger reactivity for shallowRef
    // shallowRef only tracks reference changes, not deep mutations
    // triggerRef() notifies Vue that the ref's value has changed, causing Vibe's computed to re-evaluate
    triggerRef(items);
}
