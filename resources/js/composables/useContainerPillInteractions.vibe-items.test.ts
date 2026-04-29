import { beforeEach, describe, expect, it, vi } from 'vitest';
import { computed, ref } from 'vue';
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

describe('useContainerPillInteractions current Vibe items', () => {
    const onReaction = vi.fn();
    const onlineMode = computed(() => false);

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('uses the current interaction items for online sibling reactions', async () => {
        const staleItems = ref<FeedItem[]>([createItem(1)]);
        const currentItems = [createItem(2), createItem(3)];
        const masonry = ref({
            remove: vi.fn().mockResolvedValue(undefined),
        });

        const { batchReactToSiblings } = useContainerPillInteractions({
            items: staleItems,
            getItems: () => currentItems,
            masonry,
            tabId: 1,
            isLocal: onlineMode,
            onReaction,
        });

        await batchReactToSiblings(1, 'like');

        expect(masonry.value.remove).toHaveBeenCalledWith(currentItems);
        expect(mockQueueBatchReaction).toHaveBeenCalledWith(
            [2, 3],
            'like',
            [
                { fileId: 2, thumbnail: 'https://example.com/image2.jpg' },
                { fileId: 3, thumbnail: 'https://example.com/image3.jpg' },
            ],
            expect.any(Function),
            staleItems,
            { updateLocalState: false },
        );
    });

    it('does not queue an online sibling reaction when Vibe reports nothing was removed', async () => {
        const items = ref<FeedItem[]>([createItem(1)]);
        const masonry = ref({
            remove: vi.fn().mockResolvedValue({ ids: [] }),
        });

        const { batchReactToSiblings } = useContainerPillInteractions({
            items,
            masonry,
            tabId: 1,
            isLocal: onlineMode,
            onReaction,
        });

        await batchReactToSiblings(1, 'like');

        expect(mockQueueBatchReaction).not.toHaveBeenCalled();
        expect(onReaction).not.toHaveBeenCalled();
    });
});
