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

    // Increment preview count when item is preloaded (batched)
    async function handleItemPreload(fileId: number): Promise<void> {
        // Skip if we've already incremented preview count for this item
        if (previewedItems.value.has(fileId)) {
            return;
        }

        try {
            // Queue the preview increment (will be batched with other requests)
            const response = await queuePreviewIncrement(fileId);

            // Mark as previewed
            previewedItems.value.add(fileId);

            // Update local item state - update in both items.value and tab.itemsData
            const itemIndex = items.value.findIndex((i) => i.id === fileId);
            if (itemIndex !== -1) {
                // Update the item in place to maintain reactivity
                // Use Object.assign to mutate in place, which Vue can track better
                const currentItem = items.value[itemIndex];
                if (response.auto_disliked) {
                    currentItem.auto_disliked = true;
                }
                currentItem.previewed_count = response.previewed_count;
            }

            // Also update in tab.itemsData if it exists
            if (tab.value?.itemsData) {
                const tabItemIndex = tab.value.itemsData.findIndex((i) => i.id === fileId);
                if (tabItemIndex !== -1) {
                    Object.assign(tab.value.itemsData[tabItemIndex], {
                        previewed_count: response.previewed_count,
                        auto_disliked: response.auto_disliked ? true : tab.value.itemsData[tabItemIndex].auto_disliked ?? false,
                    });
                }
            }

            // Force reactivity update
            await nextTick();
        } catch (error) {
            console.error('Failed to increment preview count:', error);
            // Don't throw - preview count is not critical
        }
    }

    // Clear previewed items (useful when switching tabs)
    function clearPreviewedItems(): void {
        previewedItems.value.clear();
    }

    return {
        previewedItems,
        handleItemPreload,
        clearPreviewedItems,
    };
}

