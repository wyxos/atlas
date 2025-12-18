import { ref } from 'vue';
import type { ReactionType } from '@/types/reaction';
import { batchStore as batchStoreReaction } from '@/actions/App/Http/Controllers/FileReactionController';

interface PendingReaction {
    fileId: number;
    type: ReactionType;
    resolve: (value: { file_id: number; reaction: { type: string } | null }) => void;
    reject: (error: any) => void;
}

const BATCH_DELAY_MS = 300; // Wait 300ms to collect requests before sending
const MAX_BATCH_SIZE = 50; // Maximum number of reactions per batch

const pendingReactions = ref<Map<string, PendingReaction>>(new Map()); // Key: `${fileId}-${type}`
let batchTimeout: ReturnType<typeof setTimeout> | null = null;

/**
 * Composable to batch reaction store requests.
 * Collects requests for a short period and sends them together.
 */
export function useReactionBatchStore(tabId?: number) {
    async function batchStoreReactions(
        reactions: Array<{ file_id: number; type: ReactionType }>
    ): Promise<Array<{ file_id: number; reaction: { type: string } | null }>> {
        try {
            const payload: {
                reactions: Array<{ file_id: number; type: ReactionType }>;
                tab_id?: number;
            } = { reactions };
            if (tabId !== undefined) {
                payload.tab_id = tabId;
            }
            const response = await window.axios.post<{
                message: string;
                reactions: Array<{ file_id: number; reaction: { type: string } | null }>;
            }>(batchStoreReaction.url(), payload);

            return response.data.reactions;
        } catch (error) {
            console.error('Failed to batch store reactions:', error);
            throw error;
        }
    }

    function flushBatch(): void {
        if (pendingReactions.value.size === 0) {
            return;
        }

        const reactions = Array.from(pendingReactions.value.values());
        const pendingMap = new Map(pendingReactions.value);
        pendingReactions.value.clear();

        if (batchTimeout) {
            clearTimeout(batchTimeout);
            batchTimeout = null;
        }

        // Group by type to batch similar reactions together
        const reactionsByType = new Map<ReactionType, Array<{ file_id: number; type: ReactionType }>>();

        reactions.forEach((pending) => {
            if (!reactionsByType.has(pending.type)) {
                reactionsByType.set(pending.type, []);
            }
            reactionsByType.get(pending.type)!.push({
                file_id: pending.fileId,
                type: pending.type,
            });
        });

        // Process each type group in chunks
        reactionsByType.forEach(async (typeReactions, type) => {
            const chunks: Array<Array<{ file_id: number; type: ReactionType }>> = [];
            for (let i = 0; i < typeReactions.length; i += MAX_BATCH_SIZE) {
                chunks.push(typeReactions.slice(i, i + MAX_BATCH_SIZE));
            }

            // Process each chunk
            for (const chunk of chunks) {
                try {
                    const results = await batchStoreReactions(chunk);
                    const resultsMap = new Map(results.map((r) => [`${r.file_id}-${type}`, r]));

                    // Resolve all promises for this chunk
                    chunk.forEach((reaction) => {
                        const key = `${reaction.file_id}-${reaction.type}`;
                        const pending = pendingMap.get(key);
                        if (pending) {
                            const result = resultsMap.get(key);
                            if (result) {
                                pending.resolve(result);
                            } else {
                                pending.reject(new Error(`No result for file ${reaction.file_id}`));
                            }
                        }
                    });
                } catch (error) {
                    // Reject all promises for this chunk
                    chunk.forEach((reaction) => {
                        const key = `${reaction.file_id}-${reaction.type}`;
                        const pending = pendingMap.get(key);
                        if (pending) {
                            pending.reject(error);
                        }
                    });
                }
            }
        });
    }

    function scheduleBatch(): void {
        if (batchTimeout) {
            return; // Already scheduled
        }

        batchTimeout = setTimeout(() => {
            flushBatch();
        }, BATCH_DELAY_MS);
    }

    /**
     * Queue a reaction store request.
     * Returns a promise that resolves when the batch request completes.
     */
    function queueReactionStore(
        fileId: number,
        type: ReactionType
    ): Promise<{ file_id: number; reaction: { type: string } | null }> {
        return new Promise((resolve, reject) => {
            const key = `${fileId}-${type}`;

            // If already pending, reject the new request (shouldn't happen, but safety check)
            if (pendingReactions.value.has(key)) {
                reject(new Error(`Reaction store already queued for file ${fileId} with type ${type}`));
                return;
            }

            pendingReactions.value.set(key, { fileId, type, resolve, reject });
            scheduleBatch();
        });
    }

    // Note: This is a shared batching service, so we don't use onUnmounted cleanup.
    // Requests will complete naturally, and if a component unmounts, it simply won't
    // handle the result (which is fine - the promise resolves but nothing listens).
    // The timeout is already cleared in flushBatch() when requests are processed.

    return {
        queueReactionStore,
        flushBatch, // Expose for manual flushing if needed
    };
}

