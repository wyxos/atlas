import { ref } from 'vue';

interface PendingPreview {
    fileId: number;
    resolve: (value: { previewed_count: number; will_auto_dislike: boolean }) => void;
    reject: (error: any) => void;
}

const BATCH_DELAY_MS = 300; // Wait 300ms to collect requests before sending
const MAX_BATCH_SIZE = 50; // Maximum number of file IDs per batch

const pendingPreviews = ref<Map<number, PendingPreview>>(new Map());
let batchTimeout: ReturnType<typeof setTimeout> | null = null;

/**
 * Composable to batch preview increment requests.
 * Collects requests for a short period and sends them together.
 */
export function usePreviewBatch() {
    async function batchIncrementPreview(fileIds: number[]): Promise<Array<{ id: number; previewed_count: number; will_auto_dislike: boolean }>> {
        try {
            const response = await window.axios.post<{
                message: string;
                results: Array<{ id: number; previewed_count: number; will_auto_dislike: boolean }>;
            }>('/api/files/preview/batch', {
                file_ids: fileIds,
            });

            return response.data.results;
        } catch (error) {
            console.error('Failed to batch increment preview counts:', error);
            throw error;
        }
    }

    function flushBatch(): void {
        if (pendingPreviews.value.size === 0) {
            return;
        }

        const fileIds = Array.from(pendingPreviews.value.keys());
        const previews = new Map(pendingPreviews.value);
        pendingPreviews.value.clear();

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
                const results = await batchIncrementPreview(chunk);
                const resultsMap = new Map(results.map((r) => [r.id, r]));

                // Resolve all promises for this chunk
                chunk.forEach((fileId) => {
                    const pending = previews.get(fileId);
                    if (pending) {
                        const result = resultsMap.get(fileId);
                        if (result) {
                            pending.resolve({
                                previewed_count: result.previewed_count,
                                will_auto_dislike: result.will_auto_dislike,
                            });
                        } else {
                            // If result not found, reject
                            pending.reject(new Error(`No result for file ${fileId}`));
                        }
                    }
                });
            } catch (error) {
                // Reject all promises for this chunk
                chunk.forEach((fileId) => {
                    const pending = previews.get(fileId);
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
     * Queue a preview increment request.
     * Returns a promise that resolves when the batch request completes.
     */
    function queuePreviewIncrement(fileId: number): Promise<{ previewed_count: number; will_auto_dislike: boolean }> {
        return new Promise((resolve, reject) => {
            // If already pending, reject the new request (shouldn't happen, but safety check)
            if (pendingPreviews.value.has(fileId)) {
                reject(new Error(`Preview increment already queued for file ${fileId}`));
                return;
            }

            pendingPreviews.value.set(fileId, { fileId, resolve, reject });
            scheduleBatch();
        });
    }

    // Note: This is a shared batching service, so we don't use onUnmounted cleanup.
    // Requests will complete naturally, and if a component unmounts, it simply won't
    // handle the result (which is fine - the promise resolves but nothing listens).
    // The timeout is already cleared in flushBatch() when requests are processed.

    return {
        queuePreviewIncrement,
        flushBatch, // Expose for manual flushing if needed
    };
}

