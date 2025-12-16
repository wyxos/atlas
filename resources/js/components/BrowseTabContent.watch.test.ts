import { describe, it, expect, vi, beforeEach } from 'vitest';
import { ref, watch, nextTick } from 'vue';
import type { MasonryItem } from '@/composables/useBrowseTabs';

/**
 * Unit tests for watch behavior detecting will_auto_dislike changes.
 * These tests verify that the watch correctly detects changes with immediate: true.
 */
describe('BrowseTabContent - will_auto_dislike watch', () => {
    let items: ReturnType<typeof ref<MasonryItem[]>>;
    let autoDislikeQueue: {
        addToQueue: (id: number, startActive: boolean) => void;
        removeFromQueue: (id: number) => void;
        isQueued: (id: number) => boolean;
    };
    let addToQueueSpy: ReturnType<typeof vi.fn>;
    let removeFromQueueSpy: ReturnType<typeof vi.fn>;
    let watchCallback: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        items = ref<MasonryItem[]>([]);
        addToQueueSpy = vi.fn();
        removeFromQueueSpy = vi.fn();

        autoDislikeQueue = {
            addToQueue: addToQueueSpy,
            removeFromQueue: removeFromQueueSpy,
            isQueued: vi.fn(() => false),
        };

        // Simulate the watch logic from BrowseTabContent
        watchCallback = vi.fn((newItems, oldItems) => {
            const oldMap = new Map(oldItems?.map((i) => [i.id, i.will_auto_dislike]) ?? []);
            newItems.forEach((item) => {
                // Add to queue if will_auto_dislike is true and wasn't before
                if (item.will_auto_dislike && !oldMap.get(item.id)) {
                    autoDislikeQueue.addToQueue(item.id, false);
                }
                // Remove from queue if will_auto_dislike is false and was true before
                else if (!item.will_auto_dislike && oldMap.get(item.id)) {
                    autoDislikeQueue.removeFromQueue(item.id);
                }
            });
        });
    });

    it('detects will_auto_dislike on initial load with immediate: true', async () => {
        // Set up items with will_auto_dislike already set
        items.value = [
            { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false, will_auto_dislike: true },
            { id: 2, width: 300, height: 400, src: 'test2.jpg', type: 'image', page: 1, index: 1, notFound: false, will_auto_dislike: false },
        ];

        // Watch with immediate: true (this is the key fix)
        watch(
            () => items.value.map((item) => ({ id: item.id, will_auto_dislike: item.will_auto_dislike })),
            watchCallback,
            { deep: true, immediate: true }
        );

        await nextTick();

        // Should have called addToQueue for item 1 (will_auto_dislike: true)
        expect(addToQueueSpy).toHaveBeenCalledWith(1, false);
        expect(addToQueueSpy).toHaveBeenCalledTimes(1);
        expect(removeFromQueueSpy).not.toHaveBeenCalled();
    });

    it('does not detect will_auto_dislike on initial load without immediate: true', async () => {
        items.value = [
            { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false, will_auto_dislike: true },
        ];

        // Watch WITHOUT immediate: true (this was the bug)
        watch(
            () => items.value.map((item) => ({ id: item.id, will_auto_dislike: item.will_auto_dislike })),
            watchCallback,
            { deep: true } // No immediate: true
        );

        await nextTick();

        // Should NOT have called addToQueue (this was the bug)
        expect(addToQueueSpy).not.toHaveBeenCalled();
    });

    it('detects when will_auto_dislike changes from false to true', async () => {
        items.value = [
            { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false, will_auto_dislike: false },
        ];

        watch(
            () => items.value.map((item) => ({ id: item.id, will_auto_dislike: item.will_auto_dislike })),
            watchCallback,
            { deep: true, immediate: true }
        );

        await nextTick();

        // Change will_auto_dislike to true
        items.value[0].will_auto_dislike = true;
        await nextTick();

        // Should have called addToQueue
        expect(addToQueueSpy).toHaveBeenCalledWith(1, false);
    });

    it('detects when will_auto_dislike changes from true to false', async () => {
        items.value = [
            { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false, will_auto_dislike: true },
        ];

        watch(
            () => items.value.map((item) => ({ id: item.id, will_auto_dislike: item.will_auto_dislike })),
            watchCallback,
            { deep: true, immediate: true }
        );

        await nextTick();
        addToQueueSpy.mockClear();

        // Change will_auto_dislike to false
        items.value[0].will_auto_dislike = false;
        await nextTick();

        // Should have called removeFromQueue
        expect(removeFromQueueSpy).toHaveBeenCalledWith(1);
    });

    it('handles multiple items with will_auto_dislike changes', async () => {
        items.value = [
            { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false, will_auto_dislike: false },
            { id: 2, width: 300, height: 400, src: 'test2.jpg', type: 'image', page: 1, index: 1, notFound: false, will_auto_dislike: false },
        ];

        watch(
            () => items.value.map((item) => ({ id: item.id, will_auto_dislike: item.will_auto_dislike })),
            watchCallback,
            { deep: true, immediate: true }
        );

        await nextTick();

        // Change both to true
        items.value[0].will_auto_dislike = true;
        items.value[1].will_auto_dislike = true;
        await nextTick();

        // Should have called addToQueue for both
        expect(addToQueueSpy).toHaveBeenCalledWith(1, false);
        expect(addToQueueSpy).toHaveBeenCalledWith(2, false);
        expect(addToQueueSpy).toHaveBeenCalledTimes(2);
    });

    it('does not add duplicate items to queue', async () => {
        items.value = [
            { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false, will_auto_dislike: true },
        ];

        watch(
            () => items.value.map((item) => ({ id: item.id, will_auto_dislike: item.will_auto_dislike })),
            watchCallback,
            { deep: true, immediate: true }
        );

        await nextTick();
        addToQueueSpy.mockClear();

        // Trigger watch again without changing will_auto_dislike
        items.value = [...items.value]; // Trigger reactivity
        await nextTick();

        // Should not call addToQueue again (item already queued)
        expect(addToQueueSpy).not.toHaveBeenCalled();
    });

    it('handles items array being replaced entirely', async () => {
        items.value = [
            { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false, will_auto_dislike: false },
        ];

        watch(
            () => items.value.map((item) => ({ id: item.id, will_auto_dislike: item.will_auto_dislike })),
            watchCallback,
            { deep: true, immediate: true }
        );

        await nextTick();

        // Replace entire array with new items
        items.value = [
            { id: 2, width: 300, height: 400, src: 'test2.jpg', type: 'image', page: 1, index: 0, notFound: false, will_auto_dislike: true },
            { id: 3, width: 300, height: 400, src: 'test3.jpg', type: 'image', page: 1, index: 1, notFound: false, will_auto_dislike: true },
        ];

        await nextTick();

        // Should add new items to queue
        expect(addToQueueSpy).toHaveBeenCalledWith(2, false);
        expect(addToQueueSpy).toHaveBeenCalledWith(3, false);
    });
});

