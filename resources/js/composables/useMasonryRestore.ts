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
        if (!masonry.value) {
            return;
        }

        // Use Vibe's restore - it handles duplicate checks, index calculation and layout internally
        if (typeof masonry.value.restore === 'function') {
            await masonry.value.restore(item, index);
        } else {
            // Fallback if restore doesn't exist (shouldn't happen with Vibe)
            console.warn('[useMasonryRestore] restore not available on masonry instance');
        }
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

        if (!masonry.value) {
            return;
        }

        // Use Vibe's restoreMany - it handles duplicate checks, index calculation and layout internally
        if (typeof masonry.value.restoreMany === 'function') {
            const items = itemsToRestore.map(({ item }) => item);
            const indices = itemsToRestore.map(({ index }) => index);
            await masonry.value.restoreMany(items, indices);
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

