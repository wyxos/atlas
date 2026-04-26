import { computed, ref } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import { useContainerPillInteractions } from './useContainerPillInteractions';
import type { FeedItem } from './useTabs';

const { mockQueueBatchReaction } = vi.hoisted(() => ({
    mockQueueBatchReaction: vi.fn(),
}));

vi.mock('@/utils/reactionQueue', () => ({
    queueBatchReaction: mockQueueBatchReaction,
}));

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

describe('useContainerPillInteractions removal ownership', () => {
    it('does not manually remove local filtered siblings when the Vibe handle is unavailable', async () => {
        const items = ref<FeedItem[]>([createItem(1), createItem(2), createItem(3)]);
        const { batchReactToSiblings } = useContainerPillInteractions({
            items,
            masonry: ref(null),
            tabId: 1,
            isLocal: computed(() => true),
            matchesActiveLocalFilters: (item) => item.id === 1,
            onReaction: vi.fn(),
        });

        await batchReactToSiblings(1, 'like');

        expect(items.value.map((item) => item.id)).toEqual([1, 2, 3]);
        expect(mockQueueBatchReaction).toHaveBeenCalledTimes(1);
    });
});
