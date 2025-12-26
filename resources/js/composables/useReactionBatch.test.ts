import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { useReactionBatch } from './useReactionBatch';

// Mock batchShow action
vi.mock('@/actions/App/Http/Controllers/FileReactionController', () => ({
    batchShow: {
        url: () => '/api/files/reactions/batch',
    },
}));

// Mock axios
const mockAxios = {
    post: vi.fn(),
};

Object.defineProperty(window, 'axios', {
    value: mockAxios,
    writable: true,
});

describe('useReactionBatch', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.useFakeTimers();
        mockAxios.post.mockReset();
        
        // Reset batch state between tests
        const { resetBatch } = useReactionBatch();
        resetBatch();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('queues reaction fetch requests', () => {
        const { queueReactionFetch } = useReactionBatch();

        mockAxios.post.mockResolvedValue({
            data: {
                reactions: [{ file_id: 1, reaction: { type: 'like' } }],
            },
        });

        const promise = queueReactionFetch(1);

        expect(promise).toBeInstanceOf(Promise);
    });

    it('batches multiple requests together', async () => {
        const { queueReactionFetch } = useReactionBatch();

        mockAxios.post.mockResolvedValue({
            data: {
                reactions: [
                    { file_id: 1, reaction: { type: 'like' } },
                    { file_id: 2, reaction: null },
                    { file_id: 3, reaction: { type: 'love' } },
                ],
            },
        });

        const promise1 = queueReactionFetch(1);
        const promise2 = queueReactionFetch(2);
        const promise3 = queueReactionFetch(3);

        // Fast-forward time to trigger batch
        vi.advanceTimersByTime(300);

        // Wait for promises to resolve
        await Promise.all([promise1, promise2, promise3]);

        // Should have made only one API call with all file IDs
        expect(mockAxios.post).toHaveBeenCalledTimes(1);
        expect(mockAxios.post).toHaveBeenCalledWith('/api/files/reactions/batch', {
            file_ids: [1, 2, 3],
        });
    });

    it('resolves promises with correct reaction data', async () => {
        const { queueReactionFetch } = useReactionBatch();

        mockAxios.post.mockResolvedValue({
            data: {
                reactions: [
                    { file_id: 1, reaction: { type: 'like' } },
                    { file_id: 2, reaction: null },
                ],
            },
        });

        const promise1 = queueReactionFetch(1);
        const promise2 = queueReactionFetch(2);

        vi.advanceTimersByTime(300);

        const [result1, result2] = await Promise.all([promise1, promise2]);

        expect(result1).toEqual({ file_id: 1, reaction: { type: 'like' } });
        expect(result2).toEqual({ file_id: 2, reaction: null });
    });

    it('handles errors gracefully', async () => {
        const { queueReactionFetch } = useReactionBatch();

        const error = new Error('API Error');
        mockAxios.post.mockRejectedValue(error);

        const promise = queueReactionFetch(1);

        vi.advanceTimersByTime(300);

        await expect(promise).rejects.toThrow('API Error');
    });

    it('processes requests in chunks when exceeding MAX_BATCH_SIZE', async () => {
        const { queueReactionFetch } = useReactionBatch();

        // Create 60 file IDs (exceeds MAX_BATCH_SIZE of 50)
        const fileIds = Array.from({ length: 60 }, (_, i) => i + 1);

        mockAxios.post
            .mockResolvedValueOnce({
                data: {
                    reactions: fileIds.slice(0, 50).map((id) => ({
                        file_id: id,
                        reaction: null,
                    })),
                },
            })
            .mockResolvedValueOnce({
                data: {
                    reactions: fileIds.slice(50).map((id) => ({
                        file_id: id,
                        reaction: null,
                    })),
                },
            });

        const promises = fileIds.map((id) => queueReactionFetch(id));

        vi.advanceTimersByTime(300);

        await Promise.all(promises);

        // Should have made 2 API calls (50 + 10)
        expect(mockAxios.post).toHaveBeenCalledTimes(2);
        expect(mockAxios.post).toHaveBeenNthCalledWith(1, '/api/files/reactions/batch', {
            file_ids: fileIds.slice(0, 50),
        });
        expect(mockAxios.post).toHaveBeenNthCalledWith(2, '/api/files/reactions/batch', {
            file_ids: fileIds.slice(50),
        });
    });

    it('rejects duplicate requests for the same file ID', async () => {
        const { queueReactionFetch } = useReactionBatch();

        mockAxios.post.mockResolvedValue({
            data: {
                reactions: [{ file_id: 1, reaction: null }],
            },
        });

        const promise1 = queueReactionFetch(1);
        // Wait a bit to ensure first request is queued
        await vi.advanceTimersByTimeAsync(10);
        const promise2 = queueReactionFetch(1); // Duplicate

        vi.advanceTimersByTime(300);

        await expect(promise1).resolves.toBeDefined();
        await expect(promise2).rejects.toThrow('Reaction fetch already queued for file 1');
    });

    it('allows manual flushing of batch', async () => {
        const { queueReactionFetch, flushBatch } = useReactionBatch();

        mockAxios.post.mockResolvedValue({
            data: {
                reactions: [{ file_id: 1, reaction: null }],
            },
        });

        const promise = queueReactionFetch(1);

        // Flush immediately without waiting for timeout
        flushBatch();

        await promise;

        expect(mockAxios.post).toHaveBeenCalledTimes(1);
        expect(mockAxios.post).toHaveBeenCalledWith('/api/files/reactions/batch', {
            file_ids: [1],
        });
    });
});
