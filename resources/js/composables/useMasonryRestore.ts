import type { Ref } from 'vue';
import type { MasonryItem } from './useTabs';

/**
 * Composable for restoring items to masonry layout.
 */
export function useMasonryRestore(
    items: Ref<MasonryItem[]>,
    masonry: Ref<any>
) {
    /**
     * Restore item to masonry at original index.
     * Delegates to Vibe's restore method which handles all index calculation and layout internally.
     */
    async function restoreToMasonry(item: MasonryItem, index: number, masonryInstance?: any): Promise<void> {
        // Check if item already exists
        const existingIndex = items.value.findIndex((i) => i.id === item.id);
        if (existingIndex !== -1) {
            return; // Item already exists
        }

        const instance = masonryInstance || masonry.value;
        if (!instance) {
            return;
        }

        // Use Vibe's restore - it handles all index calculation and layout internally
        if (typeof instance.restore === 'function') {
            await instance.restore(item, index);
        } else {
            // Fallback if restore doesn't exist (shouldn't happen with Vibe)
            console.warn('[useMasonryRestore] restore not available on masonry instance');
        }
    }

    /**
     * Restore multiple items to masonry at their original indices.
     * Delegates to Vibe's restoreMany method which handles all index calculation and layout internally.
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

        // Filter out items that already exist
        const itemsToAdd = itemsToRestore.filter(
            ({ item }) => items.value.findIndex((i) => i.id === item.id) === -1
        );

        if (itemsToAdd.length === 0) {
            return; // All items already exist
        }

        // Use Vibe's restoreMany - it handles all index calculation and layout internally
        if (typeof instance.restoreMany === 'function') {
            const sortedItems = itemsToAdd.map(({ item }) => item);
            const sortedIndices = itemsToAdd.map(({ index }) => index);
            await instance.restoreMany(sortedItems, sortedIndices);
        } else {
            // Fallback if restoreMany doesn't exist (shouldn't happen with Vibe)
            console.warn('[useMasonryRestore] restoreMany not available on masonry instance');
        }
    }

    return {
        restoreToMasonry,
        restoreManyToMasonry,
    };
}

