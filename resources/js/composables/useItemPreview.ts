import { ref, nextTick } from 'vue';
import type { MasonryItem, BrowseTabData } from './useBrowseTabs';
import { usePreviewBatch } from './usePreviewBatch';

/**
 * Composable for handling item preview count increments.
 */
export function useItemPreview(
    items: import('vue').Ref<MasonryItem[]>,
    tab: import('vue').Ref<BrowseTabData | undefined>
) {
    const previewedItems = ref<Set<number>>(new Set());
    const { queuePreviewIncrement } = usePreviewBatch();

    // Increment preview count when item comes into view (batched)
    async function incrementPreviewCount(fileId: number): Promise<{ will_auto_dislike: boolean } | null> {
        // Skip if we've already incremented preview count for this item
        if (previewedItems.value.has(fileId)) {
            return null;
        }

        try {
            // Queue the preview increment (will be batched with other requests)
            const response = await queuePreviewIncrement(fileId);

            // Mark as previewed
            previewedItems.value.add(fileId);

            // Get existing will_auto_dislike flag before updating (to preserve moderation flags)
            const itemIndex = items.value.findIndex((i) => i.id === fileId);
            const existingFlag = itemIndex !== -1 ? items.value[itemIndex].will_auto_dislike : false;
            const combinedWillAutoDislike = existingFlag || response.will_auto_dislike;

            // Update local item state - update in both items.value and tab.itemsData
            if (itemIndex !== -1) {
                // Update the item in place to maintain reactivity
                const currentItem = items.value[itemIndex];
                currentItem.previewed_count = response.previewed_count;
                // Update will_auto_dislike flag: preserve existing flag (from moderation) OR use response (from preview count threshold)
                // This ensures moderation-flagged items keep their flag, and preview-count items get flagged when threshold is reached
                currentItem.will_auto_dislike = combinedWillAutoDislike;
            }

            // Also update in tab.itemsData if it exists
            if (tab.value?.itemsData) {
                const tabItemIndex = tab.value.itemsData.findIndex((i) => i.id === fileId);
                if (tabItemIndex !== -1) {
                    const tabExistingFlag = tab.value.itemsData[tabItemIndex].will_auto_dislike;
                    Object.assign(tab.value.itemsData[tabItemIndex], {
                        previewed_count: response.previewed_count,
                        // Preserve existing flag (from moderation) OR use response (from preview count threshold)
                        will_auto_dislike: tabExistingFlag || response.will_auto_dislike,
                    });
                }
            }

            // Force reactivity update
            await nextTick();

            // Return the combined will_auto_dislike flag (existing OR response)
            return { will_auto_dislike: combinedWillAutoDislike };
        } catch (error) {
            console.error('Failed to increment preview count:', error);
            // Don't throw - preview count is not critical
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

