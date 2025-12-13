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

    return {
        restoreToMasonry,
    };
}

