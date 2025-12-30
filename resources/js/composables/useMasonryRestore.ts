import type { Ref } from 'vue';
import type { MasonryItem } from './useTabs';
import type { Masonry } from '@wyxos/vibe';

/**
 * Composable for restoring items to masonry layout.
 */
export function useMasonryRestore(
    masonry: Ref<InstanceType<typeof Masonry> | null>
) {
    /**
     * Restore item to masonry at original index.
     * Delegates to Vibe's restore method which handles all index calculation and layout internally.
     * Vibe's restore already checks for existing items, so no need to check here.
     */
    async function restoreToMasonry(item: MasonryItem, index: number): Promise<void> {
        // Use Vibe's restore - it handles duplicate checks, index calculation and layout internally
        await masonry.value?.restore(item, index);
    }

    /**
     * Restore multiple items to masonry at their original indices.
     * Delegates to Vibe's restoreMany method which handles all index calculation and layout internally.
     * Vibe's restoreMany already filters out existing items, so no need to check here.
     */
    async function restoreManyToMasonry(
        itemsToRestore: Array<{ item: MasonryItem; index: number }>
    ): Promise<void> {
        if (itemsToRestore.length === 0) {
            return;
        }

        // Use Vibe's restoreMany - it handles duplicate checks, index calculation and layout internally
        const items = itemsToRestore.map(({ item }) => item);
        const indices = itemsToRestore.map(({ index }) => index);
        await masonry.value?.restoreMany(items, indices);
    }

    return {
        restoreToMasonry,
        restoreManyToMasonry,
    };
}

