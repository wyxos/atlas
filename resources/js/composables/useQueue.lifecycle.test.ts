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

    describe('add', () => {
        it('adds an item to the queue', () => {
            const queue = queueManager;
            const onComplete = vi.fn();

            queue.collection.add({
                id: 'test-1',
                duration: 5000,
                onComplete,
            });

            expect(queue.collection.has('test-1')).toBe(true);
            expect(queue.collection.getAll()).toHaveLength(1);
        });

        it('executes onStart callback immediately when provided', () => {
            const queue = queueManager;
            const onStart = vi.fn();
            const onComplete = vi.fn();

            queue.collection.add({
                id: 'test-1',
                duration: 5000,
                onComplete,
                onStart,
            });

            expect(onStart).toHaveBeenCalledTimes(1);
            expect(onComplete).not.toHaveBeenCalled();
        });

        it('replaces existing item with same id', () => {
            const queue = queueManager;
            const onComplete1 = vi.fn();
            const onComplete2 = vi.fn();

            queue.collection.add({
                id: 'test-1',
                duration: 5000,
                onComplete: onComplete1,
            });

            queue.collection.add({
                id: 'test-1',
                duration: 3000,
                onComplete: onComplete2,
            });

            expect(queue.collection.getAll()).toHaveLength(1);
            const item = queue.collection.getAll()[0];
            expect(item.onComplete).toBe(onComplete2);
            expect(item.duration).toBe(3000);
        });

        it('returns the item id', () => {
            const queue = queueManager;
            const id = queue.collection.add({
                id: 'test-1',
                duration: 5000,
                onComplete: vi.fn(),
            });

            expect(id).toBe('test-1');
        });
    });
    describe('update', () => {
        it('updates onComplete callback', () => {
            const queue = queueManager;
            const onComplete1 = vi.fn();
            const onComplete2 = vi.fn();

            queue.collection.add({
                id: 'test-1',
                duration: 5000,
                onComplete: onComplete1,
            });

            const updated = queue.collection.update('test-1', { onComplete: onComplete2 });

            expect(updated).toBe(true);
            const item = queue.collection.getAll()[0];
            expect(item.onComplete).toBe(onComplete2);
        });

        it('updates metadata', () => {
            const queue = queueManager;

            queue.collection.add({
                id: 'test-1',
                duration: 5000,
                onComplete: vi.fn(),
                metadata: { fileId: 123 },
            });

            queue.collection.update('test-1', { metadata: { fileId: 456, type: 'reaction' } });

            const item = queue.collection.getAll()[0];
            expect(item.metadata).toEqual({ fileId: 456, type: 'reaction' });
        });

        it('returns false if item does not exist', () => {
            const queue = queueManager;
            const updated = queue.collection.update('non-existent', { onComplete: vi.fn() });

            expect(updated).toBe(false);
        });
    });
    describe('stop and resume', () => {
        it('stops (pauses) an item', () => {
            const queue = queueManager;

            queue.collection.add({
                id: 'test-1',
                duration: 5000,
                onComplete: vi.fn(),
            });

            const stopped = queue.countdown.stop('test-1');

            expect(stopped).toBe(true);
            const item = queue.collection.getAll()[0];
            expect(item.isPaused).toBe(true);
        });

        it('resumes a paused item', () => {
            const queue = queueManager;

            queue.collection.add({
                id: 'test-1',
                duration: 5000,
                onComplete: vi.fn(),
            });

            queue.countdown.stop('test-1');
            const resumed = queue.countdown.resume('test-1');

            expect(resumed).toBe(true);
            const item = queue.collection.getAll()[0];
            expect(item.isPaused).toBe(false);
        });

        it('returns false when stopping non-existent item', () => {
            const queue = queueManager;
            const stopped = queue.countdown.stop('non-existent');

            expect(stopped).toBe(false);
        });

        it('returns false when resuming non-paused item', () => {
            const queue = queueManager;

            queue.collection.add({
                id: 'test-1',
                duration: 5000,
                onComplete: vi.fn(),
            });

            const resumed = queue.countdown.resume('test-1');

            expect(resumed).toBe(false);
        });

        it('returns false when stopping item that has not started', () => {
            const queue = queueManager;

            queue.collection.add({
                id: 'test-1',
                duration: 5000,
                onComplete: vi.fn(),
                startImmediately: false,
            });

            const stopped = queue.countdown.stop('test-1');

            expect(stopped).toBe(false);
        });

        it('returns false when resuming item that has not started', () => {
            const queue = queueManager;

            queue.collection.add({
                id: 'test-1',
                duration: 5000,
                onComplete: vi.fn(),
                startImmediately: false,
            });

            const resumed = queue.countdown.resume('test-1');

            expect(resumed).toBe(false);
        });
    });
    describe('remove', () => {
        it('removes an item from the queue', () => {
            const queue = queueManager;

            queue.collection.add({
                id: 'test-1',
                duration: 5000,
                onComplete: vi.fn(),
            });

            const removed = queue.collection.remove('test-1');

            expect(removed).toBe(true);
            expect(queue.collection.has('test-1')).toBe(false);
            expect(queue.collection.getAll()).toHaveLength(0);
        });

        it('returns false if item does not exist', () => {
            const queue = queueManager;
            const removed = queue.collection.remove('non-existent');

            expect(removed).toBe(false);
        });
    });
});
