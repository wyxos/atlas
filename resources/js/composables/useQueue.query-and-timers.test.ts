import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { queueManager } from './useQueue';

describe('queueManager', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        // Reset queue state before each test
        const queue = queueManager;
        queue.collection.reset();
    });

    afterEach(() => {
        vi.useRealTimers();
        const queue = queueManager;
        queue.collection.reset();
    });

    describe('query methods', () => {
        it('checks if item exists', () => {
            const queue = queueManager;

            expect(queue.collection.has('test-1')).toBe(false);

            queue.collection.add({
                id: 'test-1',
                duration: 5000,
                onComplete: vi.fn(),
            });

            expect(queue.collection.has('test-1')).toBe(true);
        });

        it('gets progress percentage', () => {
            const queue = queueManager;

            queue.collection.add({
                id: 'test-1',
                duration: 5000,
                onComplete: vi.fn(),
            });

            // Initially 0%
            expect(queue.query.getProgress('test-1')).toBe(0);

            // Advance time by 50% of duration
            vi.advanceTimersByTime(2500);

            // Progress should be approximately 50%
            const progress = queue.query.getProgress('test-1');
            expect(progress).toBeGreaterThan(45);
            expect(progress).toBeLessThan(55);
        });

        it('gets remaining time', () => {
            const queue = queueManager;

            queue.collection.add({
                id: 'test-1',
                duration: 5000,
                onComplete: vi.fn(),
            });

            // Initially full duration
            expect(queue.query.getRemainingTime('test-1')).toBeCloseTo(5000, 0);

            // Advance time
            vi.advanceTimersByTime(2000);

            // Remaining should be approximately 3000ms
            const remaining = queue.query.getRemainingTime('test-1');
            expect(remaining).toBeGreaterThan(2900);
            expect(remaining).toBeLessThan(3100);
        });

        it('returns 0 for non-existent items', () => {
            const queue = queueManager;

            expect(queue.query.getProgress('non-existent')).toBe(0);
            expect(queue.query.getRemainingTime('non-existent')).toBe(0);
        });

        it('gets all items', () => {
            const queue = queueManager;

            queue.collection.add({
                id: 'test-1',
                duration: 5000,
                onComplete: vi.fn(),
            });

            queue.collection.add({
                id: 'test-2',
                duration: 3000,
                onComplete: vi.fn(),
            });

            const all = queue.collection.getAll();

            expect(all).toHaveLength(2);
            expect(all.map((item) => item.id)).toEqual(['test-1', 'test-2']);
        });
    });
    describe('freeze control', () => {
        it('freezes all countdowns', () => {
            const queue = queueManager;

            queue.collection.add({
                id: 'test-1',
                duration: 5000,
                onComplete: vi.fn(),
            });

            const initialRemaining = queue.query.getRemainingTime('test-1');

            queue.freeze.freezeAll();

            expect(queue.freeze.isFrozen.value).toBe(true);

            // Advance time
            vi.advanceTimersByTime(2000);

            // Remaining time should not change when frozen
            expect(queue.query.getRemainingTime('test-1')).toBeCloseTo(initialRemaining, 0);
        });

        it('unfreezes all countdowns', async () => {
            const queue = queueManager;
            const onComplete = vi.fn();

            queue.collection.add({
                id: 'test-1',
                duration: 5000,
                onComplete,
            });

            queue.freeze.freezeAll();
            expect(queue.freeze.isFrozen.value).toBe(true);

            queue.freeze.unfreezeAll();
            // isFrozen should still be true immediately (2 second delay)
            expect(queue.freeze.isFrozen.value).toBe(true);

            // Advance time past the 2 second delay
            vi.advanceTimersByTime(2000);
            await vi.runAllTimersAsync();

            // Now it should be unfrozen
            expect(queue.freeze.isFrozen.value).toBe(false);
        });

        it('cancels a pending unfreeze across queue instances', async () => {
            const firstQueue = queueManager;
            const secondQueue = queueManager;

            firstQueue.freeze.freezeAll();
            firstQueue.freeze.unfreezeAll();

            secondQueue.freeze.freezeAll();

            vi.advanceTimersByTime(2000);
            await vi.runAllTimersAsync();

            expect(firstQueue.freeze.isFrozen.value).toBe(true);

            secondQueue.freeze.unfreezeImmediately();
            expect(firstQueue.freeze.isFrozen.value).toBe(false);
        });
    });
    describe('countdown expiration', () => {
        it('executes onComplete when countdown expires', async () => {
            const queue = queueManager;
            const onComplete = vi.fn();

            queue.collection.add({
                id: 'test-1',
                duration: 5000,
                onComplete,
            });

            // Advance time past duration
            vi.advanceTimersByTime(5000);
            await vi.runAllTimersAsync();

            expect(onComplete).toHaveBeenCalledTimes(1);
            expect(queue.collection.has('test-1')).toBe(false);
        });

        it('removes item after countdown expires', async () => {
            const queue = queueManager;

            queue.collection.add({
                id: 'test-1',
                duration: 5000,
                onComplete: vi.fn(),
            });

            expect(queue.collection.has('test-1')).toBe(true);

            // Advance time past duration
            vi.advanceTimersByTime(5000);
            await vi.runAllTimersAsync();

            expect(queue.collection.has('test-1')).toBe(false);
        });

        it('handles async onComplete callbacks', async () => {
            const queue = queueManager;
            const onComplete = vi.fn().mockResolvedValue(undefined);

            queue.collection.add({
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
            const queue = queueManager;

            expect(queue.modal.isModalOpen.value).toBe(false);

            queue.modal.setModalOpen(true);
            expect(queue.modal.isModalOpen.value).toBe(true);

            queue.modal.setModalOpen(false);
            expect(queue.modal.isModalOpen.value).toBe(false);
        });
    });
});
