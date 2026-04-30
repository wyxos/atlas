import { batchIncrementPreview } from '@/actions/App/Http/Controllers/FilesController';

type PreviewBatchResult = {
    id: number;
    previewed_count: number;
    reaction?: { type: string } | null;
    auto_disliked?: boolean;
    blacklisted_at?: string | null;
};

interface PendingPreview {
    promise: Promise<PreviewBatchResult>;
    reject: (error: unknown) => void;
    resolve: (value: PreviewBatchResult) => void;
}

const BATCH_DELAY_MS = 300;
const MAX_BATCH_SIZE = 50;

const pendingPreviews = new Map<number, PendingPreview>();
let batchTimeout: ReturnType<typeof setTimeout> | null = null;
let activeBatchPromise: Promise<void> | null = null;

async function executeBatchIncrementPreview(fileIds: number[]): Promise<PreviewBatchResult[]> {
    const { data } = await window.axios.post<{
        message: string;
        results: PreviewBatchResult[];
    }>(batchIncrementPreview.url(), {
        file_ids: fileIds,
    });

    return data.results.map((result) => ({
        id: result.id,
        previewed_count: result.previewed_count,
        reaction: result.reaction ?? null,
        auto_disliked: result.auto_disliked,
        blacklisted_at: result.blacklisted_at ?? null,
    }));
}

function clearBatchTimeout(): void {
    if (batchTimeout === null) {
        return;
    }

    clearTimeout(batchTimeout);
    batchTimeout = null;
}

function scheduleBatch(): void {
    clearBatchTimeout();

    batchTimeout = setTimeout(() => {
        batchTimeout = null;
        void flushBatch();
    }, BATCH_DELAY_MS);
}

async function processChunk(fileIds: number[], previews: Map<number, PendingPreview>): Promise<void> {
    try {
        const results = await executeBatchIncrementPreview(fileIds);
        const resultsById = new Map(results.map((result) => [result.id, result]));

        for (const fileId of fileIds) {
            const pending = previews.get(fileId);
            if (!pending) {
                continue;
            }

            const result = resultsById.get(fileId);
            if (!result) {
                pending.reject(new Error(`No result for file ${fileId}`));
                continue;
            }

            pending.resolve(result);
        }
    } catch (error) {
        console.error('Failed to batch increment preview counts:', error);

        for (const fileId of fileIds) {
            previews.get(fileId)?.reject(error);
        }
    }
}

async function flushBatch(): Promise<void> {
    if (activeBatchPromise || pendingPreviews.size === 0) {
        return;
    }

    clearBatchTimeout();

    const previews = new Map(pendingPreviews);
    pendingPreviews.clear();
    const fileIds = Array.from(previews.keys());

    activeBatchPromise = (async () => {
        for (let index = 0; index < fileIds.length; index += MAX_BATCH_SIZE) {
            const chunk = fileIds.slice(index, index + MAX_BATCH_SIZE);
            await processChunk(chunk, previews);
        }
    })();

    try {
        await activeBatchPromise;
    } finally {
        activeBatchPromise = null;

        if (pendingPreviews.size > 0) {
            scheduleBatch();
        }
    }
}

export function usePreviewBatch() {
    function queuePreviewIncrement(fileId: number): Promise<PreviewBatchResult> {
        const existing = pendingPreviews.get(fileId);
        if (existing) {
            return existing.promise;
        }

        let resolve!: PendingPreview['resolve'];
        let reject!: PendingPreview['reject'];

        const promise = new Promise<PreviewBatchResult>((resolvePromise, rejectPromise) => {
            resolve = resolvePromise;
            reject = rejectPromise;
        });

        pendingPreviews.set(fileId, {
            promise,
            resolve,
            reject,
        });
        scheduleBatch();

        return promise;
    }

    return {
        queuePreviewIncrement,
        flushBatch,
    };
}
