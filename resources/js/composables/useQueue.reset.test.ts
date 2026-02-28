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
