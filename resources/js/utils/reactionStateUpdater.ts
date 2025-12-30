import { type Ref } from 'vue';
import type { MasonryItem } from '@/composables/useTabs';
import { useBrowseForm } from '@/composables/useBrowseForm';

/**
 * Helper function to update reaction state on an item in local mode.
 * Called by reactionQueue after reaction completes.
 */
export default function updateReactionState(
    items: Ref<MasonryItem[]>,
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

    // Reassign array reference to trigger reactivity with shallowRef
    // Using slice() creates a shallow copy (O(n) but only copies references, not objects)
    // This is necessary because shallowRef only tracks reference changes, not deep mutations
    // Vibe's Masonry component reads from props.items, so it needs to see a reference change
    // For 10k items, this copies ~80KB of references (8 bytes each), which is acceptable
    items.value = items.value.slice();
}

