import { nextTick, type Ref } from 'vue';
import type { MasonryItem } from './useBrowseTabs';

/**
 * Composable for restoring items to masonry layout.
 */
export function useMasonryRestore(
    items: Ref<MasonryItem[]>,
    masonry: Ref<any>
) {
    /**
     * Restore item to masonry at original index.
     */
    async function restoreToMasonry(item: MasonryItem, index: number, masonryInstance?: any): Promise<void> {
        // Restore item to masonry at original index
        const existingIndex = items.value.findIndex((i) => i.id === item.id);
        if (existingIndex !== -1) {
            return; // Item already exists
        }

        const instance = masonryInstance || masonry.value;
        if (!instance) {
            return;
        }

        // Try to use masonry's restore method if available
        if (typeof instance.restore === 'function') {
            instance.restore(item, index);
        } else if (typeof instance.add === 'function') {
            instance.add(item, index);
        } else if (typeof instance.insert === 'function') {
            instance.insert(item, index);
        } else {
            // Fallback: manually insert at original index and refresh layout
            const clampedIndex = Math.min(index, items.value.length);
            items.value.splice(clampedIndex, 0, item);
            // Trigger layout recalculation and animation
            if (typeof instance.refreshLayout === 'function') {
                // Use nextTick to ensure Vue has processed the array change
                await nextTick();
                instance.refreshLayout(items.value);
            }
        }
    }

    /**
     * Restore multiple items to masonry at their original indices.
     * Items are restored in order of their original index to maintain layout.
     */
    async function restoreManyToMasonry(
        itemsToRestore: Array<{ item: MasonryItem; index: number }>,
        masonryInstance?: any
    ): Promise<void> {
        if (itemsToRestore.length === 0) {
            return;
        }

        const instance = masonryInstance || masonry.value;
        if (!instance) {
            return;
        }

        // Filter out items that already exist and sort by original index
        const itemsToAdd = itemsToRestore
            .filter(({ item }) => items.value.findIndex((i) => i.id === item.id) === -1)
            .sort((a, b) => a.index - b.index); // Sort by original index

        if (itemsToAdd.length === 0) {
            return; // All items already exist
        }

        // Try to use masonry's restoreMany method if available
        if (typeof instance.restoreMany === 'function') {
            const sortedItems = itemsToAdd.map(({ item }) => item);
            const sortedIndices = itemsToAdd.map(({ index }) => index);
            instance.restoreMany(sortedItems, sortedIndices);
        } else if (typeof instance.addMany === 'function') {
            const sortedItems = itemsToAdd.map(({ item }) => item);
            const sortedIndices = itemsToAdd.map(({ index }) => index);
            instance.addMany(sortedItems, sortedIndices);
        } else {
            // Fallback: restore items one by one in order
            // We need to restore in reverse order to maintain correct indices
            // (restoring from highest index to lowest prevents index shifting issues)
            const reverseOrder = [...itemsToAdd].reverse();
            for (const { item, index } of reverseOrder) {
                await restoreToMasonry(item, index, instance);
            }
            // Trigger layout recalculation after all items are restored
            if (typeof instance.refreshLayout === 'function') {
                await nextTick();
                instance.refreshLayout(items.value);
            }
        }
    }

    return {
        restoreToMasonry,
        restoreManyToMasonry,
    };
}

