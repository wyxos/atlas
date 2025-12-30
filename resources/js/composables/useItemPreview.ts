import { ref, nextTick, triggerRef } from 'vue';
import type { MasonryItem, TabData } from './useTabs';
import { usePreviewBatch } from './usePreviewBatch';

/**
 * Composable for handling item preview count increments.
 */
export function useItemPreview(
    items: import('vue').Ref<MasonryItem[]>,
    _tab: import('vue').Ref<TabData | undefined>
) {
    const previewedItems = ref<Set<number>>(new Set());
    const { queuePreviewIncrement } = usePreviewBatch();

    // Increment preview count when item comes into view (batched)
    async function incrementPreviewCount(fileId: number): Promise<{ will_auto_dislike: boolean } | null> {
        // Skip if we've already incremented preview count for this item
        // Mark as previewed IMMEDIATELY to prevent race conditions (before queueing)
        if (previewedItems.value.has(fileId)) {
            return null;
        }

        // Mark as previewed immediately to prevent duplicate calls while request is pending
        previewedItems.value.add(fileId);

        try {
            // Queue the preview increment (will be batched with other requests)
            const response = await queuePreviewIncrement(fileId);

            // Get existing will_auto_dislike flag before updating (to preserve moderation flags)
            const itemIndex = items.value.findIndex((i) => i.id === fileId);
            const existingFlag = itemIndex !== -1 ? (items.value[itemIndex].will_auto_dislike ?? false) === true : false;

            const combinedWillAutoDislike = existingFlag || response.will_auto_dislike;

            // Update local item state
            if (itemIndex !== -1) {
                // Mutate item properties in place
                // This is efficient as we're only updating properties on one object
                const item = items.value[itemIndex];
                item.previewed_count = response.previewed_count;
                item.will_auto_dislike = combinedWillAutoDislike;

                // Manually trigger reactivity for shallowRef
                // shallowRef only tracks reference changes, not deep mutations
                // triggerRef() notifies Vue that the ref's value has changed, causing Vibe's computed to re-evaluate
                triggerRef(items);

                // Force Vue to process the change before continuing
                // This ensures Masonry's v-for re-evaluates and updates slot props
                await nextTick();
            }

            // Return the combined will_auto_dislike flag (existing OR response)
            return { will_auto_dislike: combinedWillAutoDislike === true };
        } catch (error) {
            // If the error is "already queued", it means another call is handling it - that's fine
            if (error instanceof Error && error.message.includes('already queued')) {
                // Another call is already handling this - return null silently
                return null;
            }

            // For other errors, log but don't throw - preview count is not critical
            console.error('Failed to increment preview count:', error);

            // Remove from previewedItems so it can be retried if needed
            previewedItems.value.delete(fileId);

            return null;
        }
    }

    // Clear previewed items (useful when switching tabs)
    function clearPreviewedItems(): void {
        previewedItems.value.clear();
    }

    return {
        previewedItems,
        incrementPreviewCount,
        clearPreviewedItems,
    };
}

