import { ref, onUnmounted } from 'vue';

interface PendingReaction {
    fileId: number;
    type: 'love' | 'like' | 'dislike' | 'funny';
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
export function useReactionBatchStore() {
    async function batchStoreReactions(
        reactions: Array<{ file_id: number; type: 'love' | 'like' | 'dislike' | 'funny' }>
    ): Promise<Array<{ file_id: number; reaction: { type: string } | null }>> {
        try {
            const response = await window.axios.post<{
                message: string;
                reactions: Array<{ file_id: number; reaction: { type: string } | null }>;
            }>('/api/files/reactions/batch/store', {
                reactions,
            });

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
        const reactionsByType = new Map<'love' | 'like' | 'dislike' | 'funny', Array<{ file_id: number; type: 'love' | 'like' | 'dislike' | 'funny' }>>();

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
            const chunks: Array<Array<{ file_id: number; type: 'love' | 'like' | 'dislike' | 'funny' }>> = [];
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
        type: 'love' | 'like' | 'dislike' | 'funny'
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

    // Cleanup on unmount
    onUnmounted(() => {
        if (batchTimeout) {
            clearTimeout(batchTimeout);
        }
        // Reject all pending requests
        pendingReactions.value.forEach((pending) => {
            pending.reject(new Error('Component unmounted'));
        });
        pendingReactions.value.clear();
    });

    return {
        queueReactionStore,
        flushBatch, // Expose for manual flushing if needed
    };
}

