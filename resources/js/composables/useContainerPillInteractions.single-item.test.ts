import { describe, expect, it, vi } from 'vitest';
import { computed, ref } from 'vue';
import { useContainerPillInteractions } from './useContainerPillInteractions';
import type { FeedItem } from './useTabs';

function createItem(id: number): FeedItem {
    return {
        id,
        width: 500,
        height: 500,
        page: 1,
        key: `1-${id}`,
        index: id - 1,
        src: `https://example.com/image${id}.jpg`,
        containers: [{ id: 1, type: 'gallery', referrer: 'https://example.com/gallery/1' }],
    } as FeedItem;
}

describe('useContainerPillInteractions one-item containers', () => {
    it('ignores middle click listing navigation', () => {
        vi.useFakeTimers();
        const onOpenContainerTab = vi.fn();
        const event = {
            altKey: false,
            button: 1,
            preventDefault: vi.fn(),
            stopPropagation: vi.fn(),
        } as unknown as MouseEvent;

        const { handlePillAuxClick } = useContainerPillInteractions({
            items: ref([createItem(1)]),
            masonry: ref({}),
            tabId: 1,
            isLocal: computed(() => false),
            onReaction: vi.fn(),
            onOpenContainerTab,
        });

        handlePillAuxClick(1, event);
        vi.advanceTimersByTime(300);
        vi.runOnlyPendingTimers();

        expect(event.stopPropagation).toHaveBeenCalled();
        expect(onOpenContainerTab).not.toHaveBeenCalled();

        vi.useRealTimers();
    });
});
