import { ref, nextTick, triggerRef } from 'vue';
import type { MasonryItem, BrowseTabData } from './useBrowseTabs';
import { usePreviewBatch } from './usePreviewBatch';

/**
 * Composable for handling item preview count increments.
 */
export function useItemPreview(
    items: import('vue').Ref<MasonryItem[]>,
    tab: import('vue').Ref<BrowseTabData | undefined>,
    itemsMap?: import('vue').Ref<Map<number, MasonryItem>>
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
            // Use itemsMap for O(1) item existence check, then findIndex only if item exists
            // This avoids unnecessary O(n) search when item doesn't exist (important for 10k+ items)
            let itemIndex = -1;
            let existingFlag = false;

            if (itemsMap?.value?.has(fileId)) {
                // Item exists - find its index (O(n) but necessary to update array element)
                itemIndex = items.value.findIndex((i) => i.id === fileId);
                if (itemIndex !== -1) {
                    existingFlag = (items.value[itemIndex].will_auto_dislike ?? false) === true;
                }
            } else if (!itemsMap) {
                // Fallback: itemsMap not provided, use O(n) findIndex
                itemIndex = items.value.findIndex((i) => i.id === fileId);
                existingFlag = itemIndex !== -1 ? (items.value[itemIndex].will_auto_dislike ?? false) === true : false;
            }
            // If itemsMap exists but item not found, itemIndex stays -1 (item doesn't exist)

            const combinedWillAutoDislike = existingFlag || response.will_auto_dislike;

            // Update local item state - update in both items.value and tab.itemsData
            // Note: items uses shallowRef, so we need to replace the object and trigger reactivity manually.
            // Backend already ensures all properties exist, so no normalization needed.
            if (itemIndex !== -1) {
                // Create updated item object (backend already includes all properties)
                const updatedItem = {
                    ...items.value[itemIndex],
                    previewed_count: response.previewed_count,
                    will_auto_dislike: combinedWillAutoDislike,
                };

                // Replace the element - with normalized items, direct assignment + triggerRef works
                // This is O(1) efficient and ensures reactivity
                items.value[itemIndex] = updatedItem;

                // Manually trigger reactivity for shallowRef (required for array element changes)
                triggerRef(items);
            }

            // Also update in tab.itemsData if it exists
            // Note: tab is a computed ref, and itemsData is a nested array
            // To ensure reactivity, we should replace the item object, not mutate it
            if (tab.value?.itemsData) {
                const tabItemIndex = tab.value.itemsData.findIndex((i) => i.id === fileId);
                if (tabItemIndex !== -1) {
                    const tabExistingFlag = tab.value.itemsData[tabItemIndex].will_auto_dislike;
                    // Replace the item object to ensure reactivity (consistent with items array fix)
                    tab.value.itemsData[tabItemIndex] = {
                        ...tab.value.itemsData[tabItemIndex],
                        previewed_count: response.previewed_count,
                        // Preserve existing flag (from moderation) OR use response (from preview count threshold)
                        will_auto_dislike: tabExistingFlag || response.will_auto_dislike,
                    };
                }
            }

            // Force reactivity update
            await nextTick();

            // Return the combined will_auto_dislike flag (existing OR response)
            return { will_auto_dislike: combinedWillAutoDislike === true };
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

