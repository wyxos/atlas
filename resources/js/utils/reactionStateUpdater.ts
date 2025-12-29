import { type Ref, triggerRef } from 'vue';
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

    // Create updated item with new reaction
    const updatedItem = {
        ...items.value[itemIndex],
        reaction: { type: reactionType },
    };

    // Update items array using splice for shallowRef reactivity
    items.value.splice(itemIndex, 1, updatedItem);

    // Trigger reactivity for shallowRef
    triggerRef(items);
}

