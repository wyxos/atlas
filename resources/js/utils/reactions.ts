import { store as storeReaction } from '@/actions/App/Http/Controllers/FileReactionController';
import type { ReactionType } from '@/types/reaction';

/**
 * Creates a callback function for queuing reactions
 * This standardizes the reaction API call pattern across the application
 * @param tabId Optional tab ID to pass to the API for tab-specific detaching
 */
export function createReactionCallback(tabId?: number): (
    fileId: number,
    type: ReactionType
) => Promise<void> {
    return async (fileId: number, type: ReactionType) => {
        try {
            const payload: { type: ReactionType; tab_id?: number } = { type };
            if (tabId !== undefined) {
                payload.tab_id = tabId;
            }
            await window.axios.post(storeReaction.url(fileId), payload);
        } catch (error) {
            console.error('Failed to update reaction:', error);
            throw error;
        }
    };
}

