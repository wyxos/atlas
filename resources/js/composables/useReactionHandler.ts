import type { MasonryItem } from './useBrowseTabs';
import { useReactionQueue } from './useReactionQueue';
import { createReactionCallback } from '../utils/reactions';

export interface ReactionHandlerOptions {
    items: import('vue').Ref<MasonryItem[]>;
    onReaction?: (fileId: number, type: 'love' | 'like' | 'dislike' | 'funny') => void;
    removeFromMasonry?: (item: MasonryItem) => void;
}

export function useReactionHandler(options: ReactionHandlerOptions) {
    const { queueReaction } = useReactionQueue();

    async function handleReaction(
        fileId: number,
        type: 'love' | 'like' | 'dislike' | 'funny'
    ): Promise<void> {
        const item = options.items.value.find((i) => i.id === fileId);

        const itemIndex = item ? options.items.value.findIndex((i) => i.id === fileId) : -1;

        // Create restore callback to add item back to masonry at original index
        const restoreItem = item ? (tabId: number, isTabActive: (tabId: number) => boolean) => {
            // Only restore if tab is active (if tabId provided)
            if (tabId !== undefined && !isTabActive(tabId)) {
                return;
            }

            // Check if item is already in the array (avoid duplicates)
            const existingIndex = options.items.value.findIndex((i) => i.id === item.id);
            if (existingIndex === -1) {
                // Add item back at original index if available, otherwise at beginning
                if (itemIndex !== -1 && itemIndex < options.items.value.length) {
                    options.items.value.splice(itemIndex, 0, item);
                } else {
                    options.items.value.unshift(item);
                }
            }
        } : undefined;

        // Remove from masonry if callback provided
        if (item && options.removeFromMasonry) {
            options.removeFromMasonry(item);
        }

        // Queue the AJAX request with restore callback (no tabId/context for this handler)
        const previewUrl = item?.src;
        queueReaction(fileId, type, createReactionCallback(), previewUrl, restoreItem, undefined, itemIndex, item);

        // Emit to parent if callback provided
        if (options.onReaction) {
            options.onReaction(fileId, type);
        }
    }

    return {
        handleReaction,
    };
}

