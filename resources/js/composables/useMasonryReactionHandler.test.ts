import { describe, expect, it, vi, beforeEach } from 'vitest';
import { ref } from 'vue';
import type { FeedItem } from './useTabs';
import { useMasonryReactionHandler } from './useMasonryReactionHandler';

const { mockQueueReaction } = vi.hoisted(() => ({
    mockQueueReaction: vi.fn(),
}));

vi.mock('@/utils/reactionQueue', () => ({
    queueReaction: mockQueueReaction,
}));

function createItem(overrides: Partial<FeedItem> = {}): FeedItem {
    return {
        id: 1,
        width: 100,
        height: 100,
        page: 1,
        key: '1-1',
        index: 0,
        src: 'https://example.com/preview.jpg',
        preview: 'https://example.com/preview.jpg',
        reaction: { type: 'dislike' },
        auto_disliked: true,
        blacklisted_at: '2026-03-19T00:00:00Z',
        blacklist_type: 'auto',
        ...overrides,
    };
}

describe('useMasonryReactionHandler', () => {
    beforeEach(() => {
        mockQueueReaction.mockReset();
    });

    it('optimistically removes local items that stop matching the active filters and restores them on rollback', async () => {
        const item = createItem();
        const items = ref([item]);
        const masonry = ref({
            remove: vi.fn().mockResolvedValue(undefined),
            restore: vi.fn().mockResolvedValue(undefined),
        } as any);
        const onReaction = vi.fn();

        const { handleMasonryReaction } = useMasonryReactionHandler({
            items,
            masonry,
            tab: ref({ id: 5 } as any),
            isLocal: ref(true),
            matchesActiveLocalFilters: () => false,
            onReaction,
        });

        await handleMasonryReaction(item, 'love');

        expect(item.reaction).toEqual({ type: 'love' });
        expect(item.auto_disliked).toBe(false);
        expect(item.blacklisted_at).toBeNull();
        expect(masonry.value.remove).toHaveBeenCalledWith(item);
        expect(mockQueueReaction).toHaveBeenCalledWith(
            1,
            'love',
            'https://example.com/preview.jpg',
            expect.any(Function),
            items,
            expect.objectContaining({
                allowRedownloadPrompt: true,
                updateLocalState: false,
            }),
        );
        expect(onReaction).toHaveBeenCalledWith(1, 'love');

        const restoreCallback = mockQueueReaction.mock.calls[0]?.[3] as (() => Promise<void>) | undefined;
        expect(restoreCallback).toBeTypeOf('function');

        await restoreCallback?.();

        expect(item.reaction).toEqual({ type: 'dislike' });
        expect(item.auto_disliked).toBe(true);
        expect(item.blacklisted_at).toBe('2026-03-19T00:00:00Z');
        expect(masonry.value.restore).toHaveBeenCalledWith(item);
    });
});
