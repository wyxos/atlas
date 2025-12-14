import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { useAutoDislikeQueue } from './useAutoDislikeQueue';

describe('useAutoDislikeQueue', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    describe('addToQueue', () => {
        it('adds item to queue in inactive state by default', () => {
            const { addToQueue, isQueued, isActive } = useAutoDislikeQueue();

            addToQueue(1);

            expect(isQueued(1)).toBe(true);
            expect(isActive(1)).toBe(false);
        });

        it('adds item to queue in active state when startActive is true', () => {
            const { addToQueue, isQueued, isActive } = useAutoDislikeQueue();

            addToQueue(1, true);

            expect(isQueued(1)).toBe(true);
            expect(isActive(1)).toBe(true);
        });

        it('does not add duplicate items', () => {
            const { addToQueue, queueSize } = useAutoDislikeQueue();

            addToQueue(1);
            addToQueue(1);

            expect(queueSize.value).toBe(1);
        });
    });

    describe('activateItem', () => {
        it('activates an inactive queued item', () => {
            const { addToQueue, activateItem, isActive } = useAutoDislikeQueue();

            addToQueue(1);
            expect(isActive(1)).toBe(false);

            activateItem(1);
            expect(isActive(1)).toBe(true);
        });

        it('does nothing for already active items', () => {
            const { addToQueue, activateItem, isActive } = useAutoDislikeQueue();

            addToQueue(1, true);
            activateItem(1);

            expect(isActive(1)).toBe(true);
        });

        it('does nothing for non-queued items', () => {
            const { activateItem, isActive } = useAutoDislikeQueue();

            activateItem(999);

            expect(isActive(999)).toBe(false);
        });
    });

    describe('removeFromQueue', () => {
        it('removes item from queue', () => {
            const { addToQueue, removeFromQueue, isQueued } = useAutoDislikeQueue();

            addToQueue(1);
            expect(isQueued(1)).toBe(true);

            removeFromQueue(1);
            expect(isQueued(1)).toBe(false);
        });
    });

    describe('countdown tick', () => {
        it('only decrements active items', () => {
            const { addToQueue, activateItem, getRemaining } = useAutoDislikeQueue();

            addToQueue(1); // inactive
            addToQueue(2, true); // active

            vi.advanceTimersByTime(1000);

            expect(getRemaining(1)).toBe(5000); // unchanged
            expect(getRemaining(2)).toBe(4000); // decremented
        });

        it('calls onExpire callback when countdown reaches zero', () => {
            const onExpire = vi.fn();
            const { addToQueue, isQueued } = useAutoDislikeQueue(onExpire);

            addToQueue(1, true);

            vi.advanceTimersByTime(5000);

            expect(onExpire).toHaveBeenCalledWith([1]);
            expect(isQueued(1)).toBe(false);
        });

        it('batches multiple expired items in single callback', () => {
            const onExpire = vi.fn();
            const { addToQueue } = useAutoDislikeQueue(onExpire);

            addToQueue(1, true);
            addToQueue(2, true);

            vi.advanceTimersByTime(5000);

            expect(onExpire).toHaveBeenCalledTimes(1);
            expect(onExpire).toHaveBeenCalledWith(expect.arrayContaining([1, 2]));
        });
    });

    describe('freeze/unfreeze', () => {
        it('stops countdown when frozen', () => {
            const { addToQueue, freeze, getRemaining } = useAutoDislikeQueue();

            addToQueue(1, true);
            vi.advanceTimersByTime(1000);
            expect(getRemaining(1)).toBe(4000);

            freeze();
            vi.advanceTimersByTime(2000);
            expect(getRemaining(1)).toBe(4000); // unchanged
        });

        it('resumes countdown when unfrozen', () => {
            const { addToQueue, freeze, unfreeze, getRemaining } = useAutoDislikeQueue();

            addToQueue(1, true);
            vi.advanceTimersByTime(1000);

            freeze();
            vi.advanceTimersByTime(2000);

            unfreeze();
            vi.advanceTimersByTime(1000);

            expect(getRemaining(1)).toBe(3000);
        });
    });

    describe('getProgress', () => {
        it('returns 0 for inactive items', () => {
            const { addToQueue, getProgress } = useAutoDislikeQueue();

            addToQueue(1);

            expect(getProgress(1)).toBe(0);
        });

        it('returns progress based on remaining time', () => {
            const { addToQueue, getProgress } = useAutoDislikeQueue();

            addToQueue(1, true);
            vi.advanceTimersByTime(2500); // 50% elapsed

            expect(getProgress(1)).toBe(0.5);
        });
    });

    describe('clearQueue', () => {
        it('removes all items from queue', () => {
            const { addToQueue, clearQueue, queueSize } = useAutoDislikeQueue();

            addToQueue(1);
            addToQueue(2);
            addToQueue(3);

            clearQueue();

            expect(queueSize.value).toBe(0);
        });
    });

    describe('computed properties', () => {
        it('hasQueuedItems reflects queue state', () => {
            const { addToQueue, removeFromQueue, hasQueuedItems } = useAutoDislikeQueue();

            expect(hasQueuedItems.value).toBe(false);

            addToQueue(1);
            expect(hasQueuedItems.value).toBe(true);

            removeFromQueue(1);
            expect(hasQueuedItems.value).toBe(false);
        });

        it('activeQueueSize counts only active items', () => {
            const { addToQueue, activateItem, activeQueueSize } = useAutoDislikeQueue();

            addToQueue(1); // inactive
            addToQueue(2, true); // active
            addToQueue(3); // inactive

            expect(activeQueueSize.value).toBe(1);

            activateItem(1);
            expect(activeQueueSize.value).toBe(2);
        });
    });
});

