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

describe('useContainerPillInteractions shortcuts', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('favorites siblings on alt left click', async () => {
        const items = ref<FeedItem[]>([createItem(1), createItem(2)]);
        const masonry = ref({ remove: vi.fn().mockResolvedValue(undefined) });
        const { handlePillClick } = useContainerPillInteractions({
            items,
            masonry,
            tabId: 1,
            isLocal: computed(() => false),
            onReaction: vi.fn(),
        });

        handlePillClick(1, {
            button: 0,
            altKey: true,
            type: 'click',
            preventDefault: vi.fn(),
            stopPropagation: vi.fn(),
        } as unknown as MouseEvent);
        await Promise.resolve();

        expect(mockQueueBatchReaction).toHaveBeenCalledWith(
            [1, 2],
            'love',
            [
                { fileId: 1, thumbnail: 'https://example.com/image1.jpg' },
                { fileId: 2, thumbnail: 'https://example.com/image2.jpg' },
            ],
            expect.any(Function),
            items,
            { updateLocalState: false },
        );
    });

    it('likes siblings on alt middle click', async () => {
        const items = ref<FeedItem[]>([createItem(1), createItem(2)]);
        const masonry = ref({ remove: vi.fn().mockResolvedValue(undefined) });
        const { handlePillAuxClick } = useContainerPillInteractions({
            items,
            masonry,
            tabId: 1,
            isLocal: computed(() => false),
            onReaction: vi.fn(),
        });

        handlePillAuxClick(1, {
            button: 1,
            altKey: true,
            preventDefault: vi.fn(),
            stopPropagation: vi.fn(),
        } as unknown as MouseEvent);
        await Promise.resolve();

        expect(mockQueueBatchReaction).toHaveBeenCalledWith(
            [1, 2],
            'like',
            [
                { fileId: 1, thumbnail: 'https://example.com/image1.jpg' },
                { fileId: 2, thumbnail: 'https://example.com/image2.jpg' },
            ],
            expect.any(Function),
            items,
            { updateLocalState: false },
        );
    });

    it('favorites siblings on double left click', async () => {
        const items = ref<FeedItem[]>([createItem(1), createItem(2)]);
        const masonry = ref({ remove: vi.fn().mockResolvedValue(undefined) });
        const { handlePillClick } = useContainerPillInteractions({
            items,
            masonry,
            tabId: 1,
            isLocal: computed(() => false),
            onReaction: vi.fn(),
        });

        handlePillClick(1, {
            button: 0,
            altKey: false,
            type: 'dblclick',
            preventDefault: vi.fn(),
            stopPropagation: vi.fn(),
        } as unknown as MouseEvent, true);
        await Promise.resolve();

        expect(mockQueueBatchReaction).toHaveBeenCalledWith(
            [1, 2],
            'love',
            expect.any(Array),
            expect.any(Function),
            items,
            { updateLocalState: false },
        );
    });

    it('likes siblings on double middle click', async () => {
        const items = ref<FeedItem[]>([createItem(1), createItem(2)]);
        const masonry = ref({ remove: vi.fn().mockResolvedValue(undefined) });
        const { handlePillAuxClick } = useContainerPillInteractions({
            items,
            masonry,
            tabId: 1,
            isLocal: computed(() => false),
            onReaction: vi.fn(),
        });

        handlePillAuxClick(1, {
            button: 1,
            altKey: false,
            preventDefault: vi.fn(),
            stopPropagation: vi.fn(),
        } as unknown as MouseEvent);
        handlePillAuxClick(1, {
            button: 1,
            altKey: false,
            preventDefault: vi.fn(),
            stopPropagation: vi.fn(),
        } as unknown as MouseEvent);
        await Promise.resolve();

        expect(mockQueueBatchReaction).toHaveBeenCalledWith(
            [1, 2],
            'like',
            expect.any(Array),
            expect.any(Function),
            items,
            { updateLocalState: false },
        );
    });
});
