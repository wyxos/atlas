import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { queueReaction, cancelQueuedReaction } from './reactionQueue';
import { useQueue } from '@/composables/useQueue';
import type { ReactionType } from '@/types/reaction';

// Hoist mocks to avoid initialization issues
const { mockToast, mockReactionCallback } = vi.hoisted(() => {
    const toast = vi.fn();
    toast.dismiss = vi.fn();
    toast.error = vi.fn();
    toast.success = vi.fn();
    toast.info = vi.fn();
    toast.warning = vi.fn();
    const callback = vi.fn().mockResolvedValue(undefined);
    return { mockToast: toast, mockReactionCallback: callback };
});

// Mock vue-toastification
vi.mock('vue-toastification', () => ({
    useToast: () => mockToast,
    default: {},
}));

// Mock createReactionCallback
vi.mock('./reactions', () => ({
    createReactionCallback: () => mockReactionCallback,
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
});

