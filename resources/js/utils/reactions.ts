import { store as storeReaction } from '@/actions/App/Http/Controllers/FileReactionController';
import type { ReactionType } from '@/types/reaction';

const BATCH_STORE_URL = '/api/files/reactions/batch/store';
const BATCH_BLACKLIST_URL = '/api/files/blacklist/batch';

export type ReactionStoreResponse = {
    reaction: { type: ReactionType } | null;
    should_prompt_redownload?: boolean;
};

export type BatchBlacklistResult = {
    id: number;
    blacklisted_at: string;
    previewed_count?: number;
};

/**
 * Creates a callback function for queuing reactions
 * This standardizes the reaction API call pattern across the application
 */
export function createReactionCallback(): (
    fileId: number,
    type: ReactionType,
    options?: { forceDownload?: boolean }
) => Promise<ReactionStoreResponse> {
    return async (fileId: number, type: ReactionType, options?: { forceDownload?: boolean }) => {
        try {
            const { data } = await window.axios.post(storeReaction.url(fileId), {
                type,
                force_download: options?.forceDownload === true ? true : undefined,
            });

            return data as ReactionStoreResponse;
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

export function createBatchBlacklistCallback(): (
    fileIds: number[]
) => Promise<BatchBlacklistResult[]> {
    return async (fileIds: number[]) => {
        if (fileIds.length === 0) {
            return [];
        }

        const results: BatchBlacklistResult[] = [];
        const CHUNK_SIZE = 100;

        for (let i = 0; i < fileIds.length; i += CHUNK_SIZE) {
            const chunk = fileIds.slice(i, i + CHUNK_SIZE);
            const { data } = await window.axios.post<{ results?: BatchBlacklistResult[] }>(BATCH_BLACKLIST_URL, {
                file_ids: chunk,
            });

            if (Array.isArray(data.results)) {
                results.push(...data.results);
            }
        }

        return results;
    };
}
