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
