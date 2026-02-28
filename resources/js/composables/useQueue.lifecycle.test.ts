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
});
