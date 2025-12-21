import { ref } from 'vue';
import { batchShow as batchShowReactions } from '@/actions/App/Http/Controllers/FileReactionController';

interface PendingReaction {
    fileId: number;
    resolve: (value: { file_id: number; reaction: { type: string } | null }) => void;
    reject: (error: any) => void;
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
    async function executeBatchShowReactions(fileIds: number[]): Promise<Array<{ file_id: number; reaction: { type: string } | null }>> {
        try {
            const response = await window.axios.post<{
                reactions: Array<{ file_id: number; reaction: { type: string } | null }>;
            }>(batchShowReactions.url(), {
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
                const results = await executeBatchShowReactions(chunk);
                const resultsMap = new Map(results.map((r) => [r.file_id, r]));

                // Resolve all promises for this chunk
                chunk.forEach((fileId) => {
                    const pending = reactions.get(fileId);
                    if (pending) {
                        const result = resultsMap.get(fileId);
                        if (result) {
                            pending.resolve(result);
                        } else {
                            // If result not found, reject
                            pending.reject(new Error(`No result for file ${fileId}`));
                        }
                    }
                });
            } catch (error) {
                // Reject all promises for this chunk
                chunk.forEach((fileId) => {
                    const pending = reactions.get(fileId);
                    if (pending) {
                        pending.reject(error);
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
     */
    function queueReactionFetch(fileId: number): Promise<{ file_id: number; reaction: { type: string } | null }> {
        return new Promise((resolve, reject) => {
            // If already pending, reject the new request (shouldn't happen, but safety check)
            if (pendingReactions.value.has(fileId)) {
                reject(new Error(`Reaction fetch already queued for file ${fileId}`));
                return;
            }

            pendingReactions.value.set(fileId, { fileId, resolve, reject });
            scheduleBatch();
        });
    }

    /**
     * Reset the batch state (useful for testing).
     */
    function resetBatch(): void {
        pendingReactions.value.clear();
        if (batchTimeout) {
            clearTimeout(batchTimeout);
            batchTimeout = null;
        }
    }

    // Note: This is a shared batching service, so we don't use onUnmounted cleanup.
    // Requests will complete naturally, and if a component unmounts, it simply won't
    // handle the result (which is fine - the promise resolves but nothing listens).
    // The timeout is already cleared in flushBatch() when requests are processed.

    return {
        queueReactionFetch,
        flushBatch, // Expose for manual flushing if needed
        resetBatch, // Expose for testing
    };
}

