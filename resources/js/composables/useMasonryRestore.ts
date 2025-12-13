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
            // Trigger layout recalculation for proper animation
            if (typeof instance.refreshLayout === 'function') {
                await nextTick();
                instance.refreshLayout(items.value);
            }
        } else if (typeof instance.add === 'function') {
            instance.add(item, index);
            // Trigger layout recalculation for proper animation
            if (typeof instance.refreshLayout === 'function') {
                await nextTick();
                instance.refreshLayout(items.value);
            }
        } else if (typeof instance.insert === 'function') {
            instance.insert(item, index);
            // Trigger layout recalculation for proper animation
            if (typeof instance.refreshLayout === 'function') {
                await nextTick();
                instance.refreshLayout(items.value);
            }
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

        // Manually splice items into array at their original indices (like atlas does)
        // This ensures proper animation when refreshLayout is called
        itemsToAdd.forEach(({ item, index }) => {
            const clampedIndex = Math.max(0, Math.min(index, items.value.length));
            items.value.splice(clampedIndex, 0, item);
        });

        // Trigger layout recalculation using requestAnimationFrame for smooth animation
        // (matching atlas implementation pattern)
        if (typeof instance.refreshLayout === 'function') {
            await nextTick();
            requestAnimationFrame(() => {
                try {
                    instance.refreshLayout(items.value);
                } catch {
                    // ignore errors
                }
            });
        }
    }

    return {
        restoreToMasonry,
        restoreManyToMasonry,
    };
}

