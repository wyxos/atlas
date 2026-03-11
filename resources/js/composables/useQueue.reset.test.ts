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

    describe('clear and reset', () => {
        it('clears all items', () => {
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

            queue.collection.clear();

            expect(queue.collection.getAll()).toHaveLength(0);
        });

        it('resets all state', () => {
            const queue = queueManager;

            queue.collection.add({
                id: 'test-1',
                duration: 5000,
                onComplete: vi.fn(),
            });

            queue.freeze.freezeAll();
            queue.modal.setModalOpen(true);

            queue.collection.reset();

            expect(queue.collection.getAll()).toHaveLength(0);
            expect(queue.freeze.isFrozen.value).toBe(false);
            expect(queue.modal.isModalOpen.value).toBe(false);
        });
    });
});
