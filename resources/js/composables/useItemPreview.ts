import { nextTick, triggerRef } from 'vue';
import type { FeedItem, TabData } from './useTabs';
import { usePreviewBatch } from './usePreviewBatch';

/**
 * Composable for handling item preview count increments.
 */
export function useItemPreview(
    items: import('vue').Ref<FeedItem[]>,
    _tab: import('vue').Ref<TabData | undefined>
) {
    const previewedItems = new Set<number>();
    const { queuePreviewIncrement } = usePreviewBatch();

    // Increment preview count when item comes into view (batched)
    async function incrementPreviewCount(fileId: number): Promise<{ previewed_count: number } | null> {
        // Skip if we've already incremented preview count for this item
        // Mark as previewed IMMEDIATELY to prevent race conditions (before queueing)
        if (previewedItems.has(fileId)) {
            return null;
        }

        // Mark as previewed immediately to prevent duplicate calls while request is pending
        previewedItems.add(fileId);

        try {
            // Queue the preview increment (will be batched with other requests)
            const response = await queuePreviewIncrement(fileId);

            const itemIndex = items.value.findIndex((i) => i.id === fileId);

            // Update local item state
            if (itemIndex !== -1) {
                // Mutate item properties in place
                // This is efficient as we're only updating properties on one object
                const item = items.value[itemIndex];
                item.previewed_count = response.previewed_count;
                item.reaction = response.reaction ?? null;

                if (typeof response.auto_blacklisted === 'boolean') {
                    item.auto_blacklisted = response.auto_blacklisted;
                }

                if ('blacklisted_at' in response) {
                    item.blacklisted_at = response.blacklisted_at ?? null;
                }

                if (item.auto_blacklisted !== true) {
                    item.auto_blacklist_rule = null;
                }

                if (item.blacklisted_at === null || item.blacklisted_at === undefined) {
                    item.blacklist_rule = null;
                }

                // Manually trigger reactivity for shallowRef
                // shallowRef only tracks reference changes, not deep mutations
                // triggerRef() notifies Vue that the ref's value has changed, causing Vibe's computed to re-evaluate
                triggerRef(items);

                // Force Vue to process the change before continuing
                // This ensures Masonry's v-for re-evaluates and updates slot props
                await nextTick();
            }

            return { previewed_count: response.previewed_count };
        } catch (error) {
            console.error('Failed to increment preview count:', error);

            // Remove from previewedItems so it can be retried if needed
            previewedItems.delete(fileId);

            return null;
        }
    }

    // Clear previewed items (useful when switching tabs)
    function clearPreviewedItems(fileIds?: number[]): void {
        if (fileIds === undefined) {
            previewedItems.clear();
            return;
        }

        if (fileIds.length === 0) {
            return;
        }

        for (const fileId of fileIds) {
            previewedItems.delete(fileId);
        }
    }

    function markPreviewedItems(fileIds: number[]): void {
        if (fileIds.length === 0) {
            return;
        }

        for (const fileId of fileIds) {
            previewedItems.add(fileId);
        }
    }

    return {
        incrementPreviewCount,
        clearPreviewedItems,
        markPreviewedItems,
    };
}
