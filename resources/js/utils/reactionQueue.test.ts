import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { queueReaction, cancelQueuedReaction, queueBatchReaction, cancelBatchQueuedReaction } from './reactionQueue';
import { useQueue } from '@/composables/useQueue';
import type { ReactionType } from '@/types/reaction';

// Hoist mocks to avoid initialization issues
const { mockToast, mockReactionCallback, mockBatchReactionCallback } = vi.hoisted(() => {
    const toast = vi.fn();
    toast.dismiss = vi.fn();
    toast.error = vi.fn();
    toast.success = vi.fn();
    toast.info = vi.fn();
    toast.warning = vi.fn();
    const callback = vi.fn().mockResolvedValue(undefined);
    const batchCallback = vi.fn().mockResolvedValue(undefined);
    return { mockToast: toast, mockReactionCallback: callback, mockBatchReactionCallback: batchCallback };
});

// Mock vue-toastification
vi.mock('vue-toastification', () => ({
    useToast: () => mockToast,
    default: {},
}));

// Mock createReactionCallback
vi.mock('./reactions', () => ({
    createReactionCallback: () => mockReactionCallback,
    createBatchReactionCallback: () => mockBatchReactionCallback,
}));

// Mock axios
const mockAxios = {
    post: vi.fn().mockResolvedValue({ data: {} }),
};

Object.defineProperty(window, 'axios', {
    value: mockAxios,
    writable: true,
});

describe('reactionQueue', () => {
    let queue: ReturnType<typeof useQueue>;

    beforeEach(() => {
        vi.useFakeTimers();
        vi.clearAllMocks();
        mockReactionCallback.mockResolvedValue(undefined);
        mockBatchReactionCallback.mockResolvedValue(undefined);
        queue = useQueue();
        queue.reset();
    });

    afterEach(() => {
        vi.useRealTimers();
        queue.reset();
    });

    describe('queueReaction', () => {
        it('adds reaction to queue with correct id', () => {
            queueReaction(123, 'like');

            expect(queue.has('like-123')).toBe(true);
        });

        it('shows toast immediately', () => {
            queueReaction(123, 'like', 'https://example.com/thumb.jpg');

            expect(mockToast).toHaveBeenCalledTimes(1);
            const toastCall = mockToast.mock.calls[0];
            expect(toastCall[1]).toMatchObject({
                id: 'like-123',
                timeout: false,
                closeButton: false,
                closeOnClick: false,
            });
        });

        it('passes thumbnail to toast component', () => {
            const thumbnail = 'https://example.com/thumb.jpg';
            queueReaction(123, 'like', thumbnail);

            expect(mockToast).toHaveBeenCalled();
            const toastCall = mockToast.mock.calls[0];
            // Check that the component receives thumbnail prop
            expect(toastCall[0].props.thumbnail).toBe(thumbnail);
        });

        it('replaces existing reaction for same file and type', () => {
            queueReaction(123, 'like');
            expect(queue.has('like-123')).toBe(true);

            queueReaction(123, 'like');
            
            // Should still have only one item
            expect(queue.getAll().filter((item) => item.id === 'like-123')).toHaveLength(1);
            expect(mockToast.dismiss).toHaveBeenCalledWith('like-123');
        });

        it('executes reaction callback when countdown expires', async () => {
            queueReaction(123, 'like');

            // Advance time past duration (5 seconds)
            vi.advanceTimersByTime(5000);
            await vi.runAllTimersAsync();

            expect(mockReactionCallback).toHaveBeenCalledWith(123, 'like');
        });

        it('dismisses toast after successful execution', async () => {
            queueReaction(123, 'like');

            // Advance time past duration
            vi.advanceTimersByTime(5000);
            await vi.runAllTimersAsync();

            expect(mockToast.dismiss).toHaveBeenCalledWith('like-123');
        });

        it('shows error toast on execution failure', async () => {
            const error = new Error('API Error');
            mockReactionCallback.mockRejectedValueOnce(error);
            vi.spyOn(console, 'error').mockImplementation(() => {});

            queueReaction(123, 'like');

            // Advance time past duration
            vi.advanceTimersByTime(5000);
            await vi.runAllTimersAsync();

            expect(mockToast.error).toHaveBeenCalledWith(
                'Failed to save reaction',
                expect.objectContaining({
                    id: 'like-123-error',
                })
            );
            expect(mockToast.dismiss).toHaveBeenCalledWith('like-123');
        });

        it('stores metadata correctly', () => {
            const thumbnail = 'https://example.com/thumb.jpg';
            const restoreCallback = vi.fn();
            queueReaction(123, 'love', thumbnail, restoreCallback);

            const item = queue.getAll().find((item) => item.id === 'love-123');
            expect(item?.metadata).toEqual({
                fileId: 123,
                reactionType: 'love',
                thumbnail,
                restoreCallback,
            });
        });

        it('handles different reaction types', () => {
            const types: ReactionType[] = ['like', 'dislike', 'love', 'funny'];

            types.forEach((type) => {
                queueReaction(123, type);
                expect(queue.has(`${type}-123`)).toBe(true);
            });
        });
    });

    describe('cancelQueuedReaction', () => {
        it('removes reaction from queue', async () => {
            queueReaction(123, 'like');
            expect(queue.has('like-123')).toBe(true);

            await cancelQueuedReaction(123, 'like');

            expect(queue.has('like-123')).toBe(false);
        });

        it('dismisses toast when canceling', async () => {
            queueReaction(123, 'like');

            await cancelQueuedReaction(123, 'like');

            expect(mockToast.dismiss).toHaveBeenCalledWith('like-123');
        });

        it('calls restore callback when canceling', async () => {
            const restoreCallback = vi.fn().mockResolvedValue(undefined);
            queueReaction(123, 'like', undefined, restoreCallback);

            await cancelQueuedReaction(123, 'like');

            expect(restoreCallback).toHaveBeenCalledTimes(1);
        });

        it('handles restore callback errors gracefully', async () => {
            const restoreCallback = vi.fn().mockRejectedValue(new Error('Restore failed'));
            vi.spyOn(console, 'error').mockImplementation(() => {});
            
            queueReaction(123, 'like', undefined, restoreCallback);

            await expect(cancelQueuedReaction(123, 'like')).resolves.not.toThrow();
            expect(mockToast.dismiss).toHaveBeenCalledWith('like-123');
        });

        it('handles canceling non-existent reaction gracefully', async () => {
            await expect(cancelQueuedReaction(999, 'like')).resolves.not.toThrow();
        });
    });

    describe('queueBatchReaction', () => {
        it('does nothing if fileIds array is empty', () => {
            queueBatchReaction([], 'like', []);

            expect(mockToast).not.toHaveBeenCalled();
            expect(queue.getAll().length).toBe(0);
        });

        it('adds batch reaction to queue with correct id pattern', () => {
            const fileIds = [123, 456, 789];
            queueBatchReaction(fileIds, 'like', []);

            const items = queue.getAll();
            expect(items.length).toBe(1);
            expect(items[0].id).toMatch(/^batch-like-123-456-789-\d+$/);
        });

        it('shows toast immediately with BatchReactionQueueToast component', () => {
            const fileIds = [123, 456];
            const previews = [
                { fileId: 123, thumbnail: 'thumb1.jpg' },
                { fileId: 456, thumbnail: 'thumb2.jpg' },
            ];
            queueBatchReaction(fileIds, 'like', previews);

            expect(mockToast).toHaveBeenCalledTimes(1);
            const toastCall = mockToast.mock.calls[0];
            expect(toastCall[0].component.name || toastCall[0].component.__name).toBe('BatchReactionQueueToast');
            expect(toastCall[0].props).toMatchObject({
                reactionType: 'like',
                previews,
                totalCount: 2,
            });
        });

        it('passes correct props to batch toast component', () => {
            const fileIds = [1, 2, 3, 4, 5, 6];
            const previews = fileIds.map((id) => ({ fileId: id, thumbnail: `thumb${id}.jpg` }));
            queueBatchReaction(fileIds, 'dislike', previews);

            const toastCall = mockToast.mock.calls[0];
            expect(toastCall[0].props.totalCount).toBe(6);
            // Component receives all previews, but only shows first 5
            expect(toastCall[0].props.previews).toHaveLength(6);
            expect(toastCall[0].props.reactionType).toBe('dislike');
        });

        it('executes all reaction callbacks when countdown expires', async () => {
            const fileIds = [123, 456, 789];
            queueBatchReaction(fileIds, 'like', []);

            // Advance time past duration (5 seconds)
            vi.advanceTimersByTime(5000);
            await vi.runAllTimersAsync();

            expect(mockReactionCallback).toHaveBeenCalledTimes(3);
            expect(mockReactionCallback).toHaveBeenCalledWith(123, 'like');
            expect(mockReactionCallback).toHaveBeenCalledWith(456, 'like');
            expect(mockReactionCallback).toHaveBeenCalledWith(789, 'like');
        });

        it('uses batch callback for large reactions', async () => {
            const fileIds = Array.from({ length: 30 }, (_, index) => index + 1);
            queueBatchReaction(fileIds, 'dislike', []);

            vi.advanceTimersByTime(5000);
            await vi.runAllTimersAsync();

            expect(mockBatchReactionCallback).toHaveBeenCalledWith(fileIds, 'dislike');
            expect(mockReactionCallback).not.toHaveBeenCalled();
        });

        it('dismisses toast when countdown expires even if batch is still pending', async () => {
            const fileIds = Array.from({ length: 30 }, (_, index) => index + 1);
            let resolveBatch: (() => void) | null = null;
            mockBatchReactionCallback.mockImplementationOnce(
                () =>
                    new Promise<void>((resolve) => {
                        resolveBatch = resolve;
                    })
            );

            queueBatchReaction(fileIds, 'dislike', []);

            const items = queue.getAll();
            const queueId = items[0]?.id;
            expect(queueId).toBeDefined();

            vi.advanceTimersByTime(5000);
            await vi.runOnlyPendingTimersAsync();

            expect(mockToast.dismiss).toHaveBeenCalledWith(queueId);

            resolveBatch?.();
            await vi.runAllTimersAsync();
        });

        it('dismisses toast after successful batch execution', async () => {
            const fileIds = [123, 456];
            queueBatchReaction(fileIds, 'like', []);

            // Get queueId before advancing time
            const items = queue.getAll();
            const queueId = items[0]?.id;
            expect(queueId).toBeDefined();

            // Advance time past duration
            vi.advanceTimersByTime(5000);
            await vi.runAllTimersAsync();

            expect(mockToast.dismiss).toHaveBeenCalledWith(queueId);
        });

        it('shows error toast on batch execution failure', async () => {
            const error = new Error('API Error');
            mockReactionCallback.mockRejectedValueOnce(error);
            vi.spyOn(console, 'error').mockImplementation(() => {});

            const fileIds = [123, 456];
            queueBatchReaction(fileIds, 'like', []);

            // Get queueId before advancing time
            const items = queue.getAll();
            const queueId = items[0]?.id;
            expect(queueId).toBeDefined();

            // Advance time past duration
            vi.advanceTimersByTime(5000);
            await vi.runAllTimersAsync();

            expect(mockToast.error).toHaveBeenCalledWith(
                'Failed to save batch reaction',
                expect.objectContaining({
                    id: `${queueId}-error`,
                })
            );
            expect(mockToast.dismiss).toHaveBeenCalledWith(queueId);
        });

        it('stores metadata correctly', () => {
            const fileIds = [123, 456, 789];
            const previews = [
                { fileId: 123, thumbnail: 'thumb1.jpg' },
                { fileId: 456, thumbnail: 'thumb2.jpg' },
                { fileId: 789 },
            ];
            const restoreCallback = vi.fn();
            queueBatchReaction(fileIds, 'dislike', previews, restoreCallback);

            const items = queue.getAll();
            expect(items[0]?.metadata).toEqual({
                fileIds,
                reactionType: 'dislike',
                previews,
                restoreCallback,
            });
        });

        it('handles different reaction types', () => {
            const types: ReactionType[] = ['like', 'dislike', 'love', 'funny'];
            const fileIds = [123, 456];

            types.forEach((type) => {
                queueBatchReaction(fileIds, type, []);
                const items = queue.getAll();
                expect(items.some((item) => item.id.includes(`batch-${type}`))).toBe(true);
            });
        });
    });

    describe('cancelBatchQueuedReaction', () => {
        it('removes batch reaction from queue', async () => {
            const fileIds = [123, 456, 789];
            queueBatchReaction(fileIds, 'like', []);

            const items = queue.getAll();
            const queueId = items[0]?.id;
            expect(queue.has(queueId)).toBe(true);

            await cancelBatchQueuedReaction(queueId);

            expect(queue.has(queueId)).toBe(false);
        });

        it('dismisses toast when canceling', async () => {
            const fileIds = [123, 456];
            queueBatchReaction(fileIds, 'like', []);

            const items = queue.getAll();
            const queueId = items[0]?.id;

            await cancelBatchQueuedReaction(queueId);

            expect(mockToast.dismiss).toHaveBeenCalledWith(queueId);
        });

        it('calls restore callback when canceling', async () => {
            const restoreCallback = vi.fn().mockResolvedValue(undefined);
            const fileIds = [123, 456];
            queueBatchReaction(fileIds, 'like', [], restoreCallback);

            const items = queue.getAll();
            const queueId = items[0]?.id;

            await cancelBatchQueuedReaction(queueId);

            expect(restoreCallback).toHaveBeenCalledTimes(1);
        });

        it('handles restore callback errors gracefully', async () => {
            const restoreCallback = vi.fn().mockRejectedValue(new Error('Restore failed'));
            vi.spyOn(console, 'error').mockImplementation(() => {});

            const fileIds = [123, 456];
            queueBatchReaction(fileIds, 'like', [], restoreCallback);

            const items = queue.getAll();
            const queueId = items[0]?.id;

            await expect(cancelBatchQueuedReaction(queueId)).resolves.not.toThrow();
            expect(mockToast.dismiss).toHaveBeenCalledWith(queueId);
        });

        it('handles canceling non-existent batch reaction gracefully', async () => {
            await expect(cancelBatchQueuedReaction('non-existent-id')).resolves.not.toThrow();
        });
    });
});
