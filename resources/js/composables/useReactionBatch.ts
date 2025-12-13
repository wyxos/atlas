import { ref, onUnmounted } from 'vue';

interface PendingReaction {
    fileId: number;
    callbacks: Array<{
        resolve: (value: { reaction: { type: string } | null }) => void;
        reject: (error: any) => void;
    }>;
}

const BATCH_DELAY_MS = 300; // Wait 300ms to collect requests before sending
const MAX_BATCH_SIZE = 50; // Maximum number of file IDs per batch

const pendingReactions = ref<Map<number, PendingReaction>>(new Map());
let batchTimeout: ReturnType<typeof setTimeout> | null = null;

/**
 * Composable to batch reaction fetch requests.
 * Collects requests for a short period and sends them together.
 */
export function useReactionBatch() {
    async function batchFetchReactions(fileIds: number[]): Promise<Array<{ file_id: number; reaction: { type: string } | null }>> {
        try {
            const response = await window.axios.post<{
                reactions: Array<{ file_id: number; reaction: { type: string } | null }>;
            }>('/api/files/reactions/batch', {
                file_ids: fileIds,
            });

            return response.data.reactions;
        } catch (error) {
            console.error('Failed to batch fetch reactions:', error);
            throw error;
        }
    }

    function flushBatch(): void {
        if (pendingReactions.value.size === 0) {
            return;
        }

        const fileIds = Array.from(pendingReactions.value.keys());
        const reactions = new Map(pendingReactions.value);
        pendingReactions.value.clear();

        if (batchTimeout) {
            clearTimeout(batchTimeout);
            batchTimeout = null;
        }

        // Process in chunks if needed
        const chunks: number[][] = [];
        for (let i = 0; i < fileIds.length; i += MAX_BATCH_SIZE) {
            chunks.push(fileIds.slice(i, i + MAX_BATCH_SIZE));
        }

        // Process each chunk
        chunks.forEach(async (chunk) => {
            try {
                const results = await batchFetchReactions(chunk);
                const resultsMap = new Map(results.map((r) => [r.file_id, r]));

                // Resolve all promises for this chunk
                chunk.forEach((fileId) => {
                    const pending = reactions.get(fileId);
                    if (pending) {
                        const result = resultsMap.get(fileId);
                        const value = result
                            ? { reaction: result.reaction }
                            : { reaction: null };
                        
                        // Resolve all callbacks for this fileId
                        pending.callbacks.forEach(({ resolve }) => {
                            resolve(value);
                        });
                    }
                });
            } catch (error) {
                // Reject all promises for this chunk
                chunk.forEach((fileId) => {
                    const pending = reactions.get(fileId);
                    if (pending) {
                        pending.callbacks.forEach(({ reject }) => {
                            reject(error);
                        });
                    }
                });
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
     * Queue a reaction fetch request.
     * Returns a promise that resolves when the batch request completes.
     * If the same fileId is already queued, returns a new promise that will resolve with the same result.
     */
    function queueReactionFetch(fileId: number): Promise<{ reaction: { type: string } | null }> {
        return new Promise((resolve, reject) => {
            const existing = pendingReactions.value.get(fileId);
            
            if (existing) {
                // Add to existing callbacks
                existing.callbacks.push({ resolve, reject });
            } else {
                // Create new pending entry
                pendingReactions.value.set(fileId, {
                    fileId,
                    callbacks: [{ resolve, reject }],
                });
                scheduleBatch();
            }
        });
    }

    // Cleanup on unmount
    onUnmounted(() => {
        if (batchTimeout) {
            clearTimeout(batchTimeout);
        }
        // Reject all pending requests
        pendingReactions.value.forEach((pending) => {
            pending.callbacks.forEach(({ reject }) => {
                reject(new Error('Component unmounted'));
            });
        });
        pendingReactions.value.clear();
    });

    return {
        queueReactionFetch,
        flushBatch, // Expose for manual flushing if needed
    };
}

