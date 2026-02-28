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
});
