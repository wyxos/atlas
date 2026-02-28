import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { useQueue } from './useQueue';

describe('useQueue', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        // Reset queue state before each test
        const queue = useQueue();
        queue.reset();
    });

    afterEach(() => {
        vi.useRealTimers();
        const queue = useQueue();
        queue.reset();
    });

    describe('delayed start', () => {
        it('adds item without starting countdown when startImmediately is false', () => {
            const queue = useQueue();
            const onComplete = vi.fn();

            queue.add({
                id: 'test-1',
                duration: 5000,
                onComplete,
                startImmediately: false,
            });

            const item = queue.getAll()[0];
            expect(item.isStarted).toBe(false);
            expect(queue.has('test-1')).toBe(true);

            // Advance time - countdown should not progress
            const initialRemaining = queue.getRemainingTime('test-1');
            vi.advanceTimersByTime(2000);
            expect(queue.getRemainingTime('test-1')).toBeCloseTo(initialRemaining, 0);
            expect(onComplete).not.toHaveBeenCalled();
        });

        it('does not execute onStart callback when startImmediately is false', () => {
            const queue = useQueue();
            const onStart = vi.fn();
            const onComplete = vi.fn();

            queue.add({
                id: 'test-1',
                duration: 5000,
                onComplete,
                onStart,
                startImmediately: false,
            });

            expect(onStart).not.toHaveBeenCalled();
        });

        it('starts countdown when start() is called', () => {
            const queue = useQueue();
            const onStart = vi.fn();
            const onComplete = vi.fn();

            queue.add({
                id: 'test-1',
                duration: 5000,
                onComplete,
                onStart,
                startImmediately: false,
            });

            expect(queue.getAll()[0].isStarted).toBe(false);

            const started = queue.start('test-1');

            expect(started).toBe(true);
            expect(queue.getAll()[0].isStarted).toBe(true);
            expect(onStart).toHaveBeenCalledTimes(1);

            // Now countdown should progress
            const initialRemaining = queue.getRemainingTime('test-1');
            vi.advanceTimersByTime(1000);
            expect(queue.getRemainingTime('test-1')).toBeLessThan(initialRemaining);
        });

        it('returns false when starting non-existent item', () => {
            const queue = useQueue();
            const started = queue.start('non-existent');

            expect(started).toBe(false);
        });

        it('returns false when starting already started item', () => {
            const queue = useQueue();

            queue.add({
                id: 'test-1',
                duration: 5000,
                onComplete: vi.fn(),
            });

            const started = queue.start('test-1');

            expect(started).toBe(false);
        });

        it('defaults to starting immediately when startImmediately is not provided', () => {
            const queue = useQueue();
            const onStart = vi.fn();

            queue.add({
                id: 'test-1',
                duration: 5000,
                onComplete: vi.fn(),
                onStart,
            });

            expect(queue.getAll()[0].isStarted).toBe(true);
            expect(onStart).toHaveBeenCalledTimes(1);
        });

        it('items that have not started are not affected by freeze', () => {
            const queue = useQueue();
            const onComplete = vi.fn();

            queue.add({
                id: 'test-1',
                duration: 5000,
                onComplete,
                startImmediately: false,
            });

            queue.freezeAll();
            expect(queue.isFrozen.value).toBe(true);

            // Item should still not be started
            expect(queue.getAll()[0].isStarted).toBe(false);

            // Start the item while frozen
            queue.start('test-1');
            expect(queue.getAll()[0].isStarted).toBe(true);

            // Advance time - should not progress because frozen
            const remainingAfterStart = queue.getRemainingTime('test-1');
            vi.advanceTimersByTime(1000);
            expect(queue.getRemainingTime('test-1')).toBeCloseTo(remainingAfterStart, 0);

            // Unfreeze - now it should progress (after 2 second delay)
            queue.unfreezeAll();
            // Advance past the 2 second delay
            vi.advanceTimersByTime(2000);
            // Then advance 1 second for countdown progress
            vi.advanceTimersByTime(1000);
            expect(queue.getRemainingTime('test-1')).toBeLessThan(remainingAfterStart);
        });

        it('items that have started are affected by freeze', () => {
            const queue = useQueue();
            const onComplete = vi.fn();

            queue.add({
                id: 'test-1',
                duration: 5000,
                onComplete,
            });

            // Item is started, countdown should progress
            const initialRemaining = queue.getRemainingTime('test-1');
            vi.advanceTimersByTime(500);
            expect(queue.getRemainingTime('test-1')).toBeLessThan(initialRemaining);

            // Freeze - countdown should stop
            queue.freezeAll();
            const remainingWhenFrozen = queue.getRemainingTime('test-1');
            vi.advanceTimersByTime(1000);
            expect(queue.getRemainingTime('test-1')).toBeCloseTo(remainingWhenFrozen, 0);

            // Unfreeze - countdown should resume (after 2 second delay)
            queue.unfreezeAll();
            // Advance past the 2 second delay
            vi.advanceTimersByTime(2000);
            // Then advance 500ms for countdown progress
            vi.advanceTimersByTime(500);
            expect(queue.getRemainingTime('test-1')).toBeLessThan(remainingWhenFrozen);
        });

        it('can start multiple items with delayed start', () => {
            const queue = useQueue();
            const onComplete1 = vi.fn();
            const onComplete2 = vi.fn();

            queue.add({
                id: 'test-1',
                duration: 5000,
                onComplete: onComplete1,
                startImmediately: false,
            });

            queue.add({
                id: 'test-2',
                duration: 3000,
                onComplete: onComplete2,
                startImmediately: false,
            });

            expect(queue.getAll()[0].isStarted).toBe(false);
            expect(queue.getAll()[1].isStarted).toBe(false);

            // Start first item
            queue.start('test-1');
            expect(queue.getAll()[0].isStarted).toBe(true);
            expect(queue.getAll()[1].isStarted).toBe(false);

            // Advance time - only first item should progress
            const remaining1 = queue.getRemainingTime('test-1');
            const remaining2 = queue.getRemainingTime('test-2');
            vi.advanceTimersByTime(1000);
            expect(queue.getRemainingTime('test-1')).toBeLessThan(remaining1);
            expect(queue.getRemainingTime('test-2')).toBeCloseTo(remaining2, 0);

            // Start second item
            queue.start('test-2');
            expect(queue.getAll()[1].isStarted).toBe(true);

            // Both should progress now
            const newRemaining1 = queue.getRemainingTime('test-1');
            const newRemaining2 = queue.getRemainingTime('test-2');
            vi.advanceTimersByTime(1000);
            expect(queue.getRemainingTime('test-1')).toBeLessThan(newRemaining1);
            expect(queue.getRemainingTime('test-2')).toBeLessThan(newRemaining2);
        });

        it('progress and remaining time work correctly for non-started items', () => {
            const queue = useQueue();

            queue.add({
                id: 'test-1',
                duration: 5000,
                onComplete: vi.fn(),
                startImmediately: false,
            });

            // Progress should be 0% and remaining time should be full duration
            expect(queue.getProgress('test-1')).toBe(0);
            expect(queue.getRemainingTime('test-1')).toBeCloseTo(5000, 0);

            // Advance time - should not change
            vi.advanceTimersByTime(2000);
            expect(queue.getProgress('test-1')).toBe(0);
            expect(queue.getRemainingTime('test-1')).toBeCloseTo(5000, 0);

            // Start the item
            queue.start('test-1');

            // Now progress should start
            vi.advanceTimersByTime(1000);
            const progress = queue.getProgress('test-1');
            expect(progress).toBeGreaterThan(0);
            expect(progress).toBeLessThan(30); // Should be around 20%
        });
    });
});
