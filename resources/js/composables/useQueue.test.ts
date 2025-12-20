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

        it('unfreezes all countdowns', () => {
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

