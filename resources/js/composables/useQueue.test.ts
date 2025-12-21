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

    describe('add', () => {
        it('adds an item to the queue', () => {
            const queue = useQueue();
            const onComplete = vi.fn();

            queue.add({
                id: 'test-1',
                duration: 5000,
                onComplete,
            });

            expect(queue.has('test-1')).toBe(true);
            expect(queue.getAll()).toHaveLength(1);
        });

        it('executes onStart callback immediately when provided', () => {
            const queue = useQueue();
            const onStart = vi.fn();
            const onComplete = vi.fn();

            queue.add({
                id: 'test-1',
                duration: 5000,
                onComplete,
                onStart,
            });

            expect(onStart).toHaveBeenCalledTimes(1);
            expect(onComplete).not.toHaveBeenCalled();
        });

        it('replaces existing item with same id', () => {
            const queue = useQueue();
            const onComplete1 = vi.fn();
            const onComplete2 = vi.fn();

            queue.add({
                id: 'test-1',
                duration: 5000,
                onComplete: onComplete1,
            });

            queue.add({
                id: 'test-1',
                duration: 3000,
                onComplete: onComplete2,
            });

            expect(queue.getAll()).toHaveLength(1);
            const item = queue.getAll()[0];
            expect(item.onComplete).toBe(onComplete2);
            expect(item.duration).toBe(3000);
        });

        it('returns the item id', () => {
            const queue = useQueue();
            const id = queue.add({
                id: 'test-1',
                duration: 5000,
                onComplete: vi.fn(),
            });

            expect(id).toBe('test-1');
        });
    });

    describe('update', () => {
        it('updates onComplete callback', () => {
            const queue = useQueue();
            const onComplete1 = vi.fn();
            const onComplete2 = vi.fn();

            queue.add({
                id: 'test-1',
                duration: 5000,
                onComplete: onComplete1,
            });

            const updated = queue.update('test-1', { onComplete: onComplete2 });

            expect(updated).toBe(true);
            const item = queue.getAll()[0];
            expect(item.onComplete).toBe(onComplete2);
        });

        it('updates metadata', () => {
            const queue = useQueue();

            queue.add({
                id: 'test-1',
                duration: 5000,
                onComplete: vi.fn(),
                metadata: { fileId: 123 },
            });

            queue.update('test-1', { metadata: { fileId: 456, type: 'reaction' } });

            const item = queue.getAll()[0];
            expect(item.metadata).toEqual({ fileId: 456, type: 'reaction' });
        });

        it('returns false if item does not exist', () => {
            const queue = useQueue();
            const updated = queue.update('non-existent', { onComplete: vi.fn() });

            expect(updated).toBe(false);
        });
    });

    describe('stop and resume', () => {
        it('stops (pauses) an item', () => {
            const queue = useQueue();

            queue.add({
                id: 'test-1',
                duration: 5000,
                onComplete: vi.fn(),
            });

            const stopped = queue.stop('test-1');

            expect(stopped).toBe(true);
            const item = queue.getAll()[0];
            expect(item.isPaused).toBe(true);
        });

        it('resumes a paused item', () => {
            const queue = useQueue();

            queue.add({
                id: 'test-1',
                duration: 5000,
                onComplete: vi.fn(),
            });

            queue.stop('test-1');
            const resumed = queue.resume('test-1');

            expect(resumed).toBe(true);
            const item = queue.getAll()[0];
            expect(item.isPaused).toBe(false);
        });

        it('returns false when stopping non-existent item', () => {
            const queue = useQueue();
            const stopped = queue.stop('non-existent');

            expect(stopped).toBe(false);
        });

        it('returns false when resuming non-paused item', () => {
            const queue = useQueue();

            queue.add({
                id: 'test-1',
                duration: 5000,
                onComplete: vi.fn(),
            });

            const resumed = queue.resume('test-1');

            expect(resumed).toBe(false);
        });

        it('returns false when stopping item that has not started', () => {
            const queue = useQueue();

            queue.add({
                id: 'test-1',
                duration: 5000,
                onComplete: vi.fn(),
                startImmediately: false,
            });

            const stopped = queue.stop('test-1');

            expect(stopped).toBe(false);
        });

        it('returns false when resuming item that has not started', () => {
            const queue = useQueue();

            queue.add({
                id: 'test-1',
                duration: 5000,
                onComplete: vi.fn(),
                startImmediately: false,
            });

            const resumed = queue.resume('test-1');

            expect(resumed).toBe(false);
        });
    });

    describe('remove', () => {
        it('removes an item from the queue', () => {
            const queue = useQueue();

            queue.add({
                id: 'test-1',
                duration: 5000,
                onComplete: vi.fn(),
            });

            const removed = queue.remove('test-1');

            expect(removed).toBe(true);
            expect(queue.has('test-1')).toBe(false);
            expect(queue.getAll()).toHaveLength(0);
        });

        it('returns false if item does not exist', () => {
            const queue = useQueue();
            const removed = queue.remove('non-existent');

            expect(removed).toBe(false);
        });
    });

    describe('query methods', () => {
        it('checks if item exists', () => {
            const queue = useQueue();

            expect(queue.has('test-1')).toBe(false);

            queue.add({
                id: 'test-1',
                duration: 5000,
                onComplete: vi.fn(),
            });

            expect(queue.has('test-1')).toBe(true);
        });

        it('gets progress percentage', () => {
            const queue = useQueue();

            queue.add({
                id: 'test-1',
                duration: 5000,
                onComplete: vi.fn(),
            });

            // Initially 0%
            expect(queue.getProgress('test-1')).toBe(0);

            // Advance time by 50% of duration
            vi.advanceTimersByTime(2500);

            // Progress should be approximately 50%
            const progress = queue.getProgress('test-1');
            expect(progress).toBeGreaterThan(45);
            expect(progress).toBeLessThan(55);
        });

        it('gets remaining time', () => {
            const queue = useQueue();

            queue.add({
                id: 'test-1',
                duration: 5000,
                onComplete: vi.fn(),
            });

            // Initially full duration
            expect(queue.getRemainingTime('test-1')).toBeCloseTo(5000, 0);

            // Advance time
            vi.advanceTimersByTime(2000);

            // Remaining should be approximately 3000ms
            const remaining = queue.getRemainingTime('test-1');
            expect(remaining).toBeGreaterThan(2900);
            expect(remaining).toBeLessThan(3100);
        });

        it('returns 0 for non-existent items', () => {
            const queue = useQueue();

            expect(queue.getProgress('non-existent')).toBe(0);
            expect(queue.getRemainingTime('non-existent')).toBe(0);
        });

        it('gets all items', () => {
            const queue = useQueue();

            queue.add({
                id: 'test-1',
                duration: 5000,
                onComplete: vi.fn(),
            });

            queue.add({
                id: 'test-2',
                duration: 3000,
                onComplete: vi.fn(),
            });

            const all = queue.getAll();

            expect(all).toHaveLength(2);
            expect(all.map((item) => item.id)).toEqual(['test-1', 'test-2']);
        });
    });

    describe('freeze control', () => {
        it('freezes all countdowns', () => {
            const queue = useQueue();

            queue.add({
                id: 'test-1',
                duration: 5000,
                onComplete: vi.fn(),
            });

            const initialRemaining = queue.getRemainingTime('test-1');

            queue.freezeAll();

            expect(queue.isFrozen.value).toBe(true);

            // Advance time
            vi.advanceTimersByTime(2000);

            // Remaining time should not change when frozen
            expect(queue.getRemainingTime('test-1')).toBeCloseTo(initialRemaining, 0);
        });

        it('unfreezes all countdowns', async () => {
            const queue = useQueue();
            const onComplete = vi.fn();

            queue.add({
                id: 'test-1',
                duration: 5000,
                onComplete,
            });

            queue.freezeAll();
            expect(queue.isFrozen.value).toBe(true);

            queue.unfreezeAll();
            // isFrozen should still be true immediately (2 second delay)
            expect(queue.isFrozen.value).toBe(true);

            // Advance time past the 2 second delay
            vi.advanceTimersByTime(2000);
            await vi.runAllTimersAsync();

            // Now it should be unfrozen
            expect(queue.isFrozen.value).toBe(false);
        });
    });

    describe('countdown expiration', () => {
        it('executes onComplete when countdown expires', async () => {
            const queue = useQueue();
            const onComplete = vi.fn();

            queue.add({
                id: 'test-1',
                duration: 5000,
                onComplete,
            });

            // Advance time past duration
            vi.advanceTimersByTime(5000);
            await vi.runAllTimersAsync();

            expect(onComplete).toHaveBeenCalledTimes(1);
            expect(queue.has('test-1')).toBe(false);
        });

        it('removes item after countdown expires', async () => {
            const queue = useQueue();

            queue.add({
                id: 'test-1',
                duration: 5000,
                onComplete: vi.fn(),
            });

            expect(queue.has('test-1')).toBe(true);

            // Advance time past duration
            vi.advanceTimersByTime(5000);
            await vi.runAllTimersAsync();

            expect(queue.has('test-1')).toBe(false);
        });

        it('handles async onComplete callbacks', async () => {
            const queue = useQueue();
            const onComplete = vi.fn().mockResolvedValue(undefined);

            queue.add({
                id: 'test-1',
                duration: 5000,
                onComplete,
            });

            // Advance time past duration
            vi.advanceTimersByTime(5000);
            await vi.runAllTimersAsync();

            expect(onComplete).toHaveBeenCalledTimes(1);
        });
    });

    describe('modal state', () => {
        it('sets modal open state', () => {
            const queue = useQueue();

            expect(queue.isModalOpen.value).toBe(false);

            queue.setModalOpen(true);
            expect(queue.isModalOpen.value).toBe(true);

            queue.setModalOpen(false);
            expect(queue.isModalOpen.value).toBe(false);
        });
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

    describe('clear and reset', () => {
        it('clears all items', () => {
            const queue = useQueue();

            queue.add({
                id: 'test-1',
                duration: 5000,
                onComplete: vi.fn(),
            });

            queue.add({
                id: 'test-2',
                duration: 3000,
                onComplete: vi.fn(),
            });

            queue.clear();

            expect(queue.getAll()).toHaveLength(0);
        });

        it('resets all state', () => {
            const queue = useQueue();

            queue.add({
                id: 'test-1',
                duration: 5000,
                onComplete: vi.fn(),
            });

            queue.freezeAll();
            queue.setModalOpen(true);

            queue.reset();

            expect(queue.getAll()).toHaveLength(0);
            expect(queue.isFrozen.value).toBe(false);
            expect(queue.isModalOpen.value).toBe(false);
        });
    });
});

