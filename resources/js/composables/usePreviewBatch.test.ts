import { beforeEach, describe, expect, it, vi } from 'vitest';

type PreviewBatchResult = {
    id: number;
    previewed_count: number;
    reaction?: { type: string } | null;
    auto_disliked?: boolean;
    blacklisted_at?: string | null;
};

function createBatchResponse(fileIds: number[]) {
    return {
        data: {
            message: 'Preview counts incremented successfully',
            results: fileIds.map((fileId) => ({
                id: fileId,
                previewed_count: fileId * 10,
                reaction: fileId === 2 ? { type: 'dislike' } : null,
                auto_disliked: fileId === 2,
                blacklisted_at: fileId === 3 ? '2026-04-30T00:00:00Z' : null,
            })),
        },
    };
}

function createDeferred<T>() {
    let resolve!: (value: T | PromiseLike<T>) => void;
    let reject!: (reason?: unknown) => void;

    const promise = new Promise<T>((resolvePromise, rejectPromise) => {
        resolve = resolvePromise;
        reject = rejectPromise;
    });

    return {
        promise,
        reject,
        resolve,
    };
}

const { mockAxios } = vi.hoisted(() => ({
    mockAxios: {
        post: vi.fn(),
    },
}));

Object.defineProperty(window, 'axios', {
    value: mockAxios,
    writable: true,
});

async function loadUsePreviewBatch() {
    vi.resetModules();

    return import('./usePreviewBatch');
}

beforeEach(() => {
    vi.clearAllMocks();
    vi.spyOn(console, 'error').mockImplementation(() => {});
});

describe('usePreviewBatch', () => {
    it('debounces preview bursts into a single trailing request', async () => {
        vi.useFakeTimers();

        try {
            mockAxios.post.mockImplementation((_url: string, payload: { file_ids: number[] }) => (
                Promise.resolve(createBatchResponse(payload.file_ids))
            ));

            const { usePreviewBatch } = await loadUsePreviewBatch();
            const { queuePreviewIncrement } = usePreviewBatch();

            const first = queuePreviewIncrement(1);
            await vi.advanceTimersByTimeAsync(200);

            const second = queuePreviewIncrement(2);
            await vi.advanceTimersByTimeAsync(200);

            const third = queuePreviewIncrement(3);
            await vi.advanceTimersByTimeAsync(299);

            expect(mockAxios.post).not.toHaveBeenCalled();

            await vi.advanceTimersByTimeAsync(1);

            expect(mockAxios.post).toHaveBeenCalledTimes(1);
            expect(mockAxios.post).toHaveBeenCalledWith('/api/files/preview/batch', {
                file_ids: [1, 2, 3],
            });
            await expect(Promise.all([first, second, third])).resolves.toEqual([
                { id: 1, previewed_count: 10, reaction: null, auto_disliked: false, blacklisted_at: null },
                { id: 2, previewed_count: 20, reaction: { type: 'dislike' }, auto_disliked: true, blacklisted_at: null },
                { id: 3, previewed_count: 30, reaction: null, auto_disliked: false, blacklisted_at: '2026-04-30T00:00:00Z' },
            ]);
        } finally {
            vi.useRealTimers();
        }
    });

    it('queues one follow-up request after the active batch settles', async () => {
        vi.useFakeTimers();

        try {
            const firstRequest = createDeferred<{
                data: {
                    message: string;
                    results: PreviewBatchResult[];
                };
            }>();

            mockAxios.post
                .mockImplementationOnce(() => firstRequest.promise)
                .mockImplementationOnce((_url: string, payload: { file_ids: number[] }) => (
                    Promise.resolve(createBatchResponse(payload.file_ids))
                ));

            const { usePreviewBatch } = await loadUsePreviewBatch();
            const { queuePreviewIncrement } = usePreviewBatch();

            const first = queuePreviewIncrement(1);
            await vi.advanceTimersByTimeAsync(300);

            expect(mockAxios.post).toHaveBeenCalledTimes(1);
            expect(mockAxios.post).toHaveBeenNthCalledWith(1, '/api/files/preview/batch', {
                file_ids: [1],
            });

            const second = queuePreviewIncrement(2);
            await vi.advanceTimersByTimeAsync(150);

            const third = queuePreviewIncrement(3);
            await vi.advanceTimersByTimeAsync(300);

            expect(mockAxios.post).toHaveBeenCalledTimes(1);

            firstRequest.resolve(createBatchResponse([1]));
            await Promise.resolve();

            await vi.advanceTimersByTimeAsync(299);
            expect(mockAxios.post).toHaveBeenCalledTimes(1);

            await vi.advanceTimersByTimeAsync(1);

            expect(mockAxios.post).toHaveBeenCalledTimes(2);
            expect(mockAxios.post).toHaveBeenNthCalledWith(2, '/api/files/preview/batch', {
                file_ids: [2, 3],
            });
            await expect(Promise.all([first, second, third])).resolves.toEqual([
                { id: 1, previewed_count: 10, reaction: null, auto_disliked: false, blacklisted_at: null },
                { id: 2, previewed_count: 20, reaction: { type: 'dislike' }, auto_disliked: true, blacklisted_at: null },
                { id: 3, previewed_count: 30, reaction: null, auto_disliked: false, blacklisted_at: '2026-04-30T00:00:00Z' },
            ]);
        } finally {
            vi.useRealTimers();
        }
    });
});
