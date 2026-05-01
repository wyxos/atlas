import { ref, shallowRef } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useItemPreview } from './useItemPreview';
import type { FeedItem } from './useTabs';

const { mockQueuePreviewIncrement } = vi.hoisted(() => ({
    mockQueuePreviewIncrement: vi.fn(),
}));

vi.mock('./usePreviewBatch', () => ({
    usePreviewBatch: () => ({
        queuePreviewIncrement: mockQueuePreviewIncrement,
    }),
}));

describe('useItemPreview', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('updates local reaction and moderation state from preview response', async () => {
        const item = {
            id: 1,
            width: 500,
            height: 500,
            page: 1,
            key: '1-1',
            index: 0,
            src: 'https://example.com/image.jpg',
            reaction: null,
            previewed_count: 1,
            auto_disliked: false,
            blacklisted_at: null,
        } as FeedItem;
        const items = shallowRef<FeedItem[]>([item]);
        mockQueuePreviewIncrement.mockResolvedValue({
            id: 1,
            previewed_count: 2,
            reaction: { type: 'dislike' },
            auto_disliked: true,
            blacklisted_at: null,
        });

        const preview = useItemPreview(items, ref(undefined));

        await preview.incrementPreviewCount(1);

        expect(item.previewed_count).toBe(2);
        expect(item.reaction).toEqual({ type: 'dislike' });
        expect(item.auto_disliked).toBe(true);
        expect(item.blacklisted_at).toBeNull();
    });

    it('clears local reaction and auto dislike state when preview response blacklists the item', async () => {
        const item = {
            id: 2,
            width: 500,
            height: 500,
            page: 1,
            key: '1-2',
            index: 0,
            src: 'https://example.com/image.jpg',
            reaction: { type: 'dislike' },
            previewed_count: 2,
            auto_disliked: true,
            auto_dislike_rule: { id: 1, name: 'Rule' },
            blacklisted_at: null,
        } as FeedItem;
        const items = shallowRef<FeedItem[]>([item]);
        mockQueuePreviewIncrement.mockResolvedValue({
            id: 2,
            previewed_count: 99999,
            reaction: null,
            auto_disliked: false,
            blacklisted_at: '2026-04-30T00:00:00Z',
        });

        const preview = useItemPreview(items, ref(undefined));

        await preview.incrementPreviewCount(2);

        expect(item.previewed_count).toBe(99999);
        expect(item.reaction).toBeNull();
        expect(item.auto_disliked).toBe(false);
        expect(item.auto_dislike_rule).toBeNull();
        expect(item.blacklisted_at).toBe('2026-04-30T00:00:00Z');
    });
});
