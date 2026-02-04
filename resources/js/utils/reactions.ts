import { store as storeReaction } from '@/actions/App/Http/Controllers/FileReactionController';
import type { ReactionType } from '@/types/reaction';

const BATCH_STORE_URL = '/api/files/reactions/batch/store';

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

/**
 * Creates a callback function for queuing batch reactions.
 */
export function createBatchReactionCallback(): (
    fileIds: number[],
    type: ReactionType
) => Promise<void> {
    return async (fileIds: number[], type: ReactionType) => {
        if (fileIds.length === 0) {
            return;
        }

        const CHUNK_SIZE = 100;
        for (let i = 0; i < fileIds.length; i += CHUNK_SIZE) {
            const chunk = fileIds.slice(i, i + CHUNK_SIZE);
            await window.axios.post(BATCH_STORE_URL, {
                reactions: chunk.map((fileId) => ({
                    file_id: fileId,
                    type,
                })),
            });
        }
    };
}
