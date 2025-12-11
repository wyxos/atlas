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

        // Remove from masonry if callback provided
        if (item && options.removeFromMasonry) {
            options.removeFromMasonry(item);
        }

        // Queue the AJAX request
        const previewUrl = item?.src;
        queueReaction(fileId, type, createReactionCallback(), previewUrl);

        // Emit to parent if callback provided
        if (options.onReaction) {
            options.onReaction(fileId, type);
        }
    }

    return {
        handleReaction,
    };
}

