import type { MasonryItem } from './useTabs';
import { createReactionCallback } from '../utils/reactions';
import type { ReactionType } from '@/types/reaction';

export interface ReactionHandlerOptions {
    items: import('vue').Ref<MasonryItem[]>;
    onReaction?: (fileId: number, type: ReactionType) => void;
    removeFromMasonry?: (item: MasonryItem) => void;
}

export function useReactionHandler(options: ReactionHandlerOptions) {

    async function handleReaction(
        fileId: number,
        type: ReactionType
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

        // Execute reaction callback directly
        await createReactionCallback()(fileId, type);

        // Emit to parent if callback provided
        if (options.onReaction) {
            options.onReaction(fileId, type);
        }
    }

    return {
        handleReaction,
    };
}

