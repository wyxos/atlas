import { store as storeReaction } from '@/actions/App/Http/Controllers/FileReactionController';
import type { ReactionType } from '@/types/reaction';

/**
 * Creates a callback function for queuing reactions
 * This standardizes the reaction API call pattern across the application
 */
export function createReactionCallback(): (
    fileId: number,
    type: ReactionType
) => Promise<void> {
    return async (fileId: number, type: ReactionType) => {
        try {
            await window.axios.post(storeReaction.url(fileId), { type });
        } catch (error) {
            console.error('Failed to update reaction:', error);
            throw error;
        }
    };
}

