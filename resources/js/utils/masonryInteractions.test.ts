import { describe, expect, it, vi } from 'vitest';
import type { FeedItem } from '@/composables/useTabs';
import { createMasonryInteractions } from './masonryInteractions';

function createFeedItem(): FeedItem {
    return { id: 1 } as FeedItem;
}

describe('masonryInteractions', () => {
    it('favorites the item on alt left click', () => {
        const handleReaction = vi.fn();
        const interactions = createMasonryInteractions(handleReaction, vi.fn());
        const item = createFeedItem();
        const event = new MouseEvent('click', { altKey: true, button: 0 });

        interactions.handleAltClickReaction(event, item, 3);

        expect(handleReaction).toHaveBeenCalledWith(item, 'love', 3);
    });

    it('likes the item on alt middle click', () => {
        const handleReaction = vi.fn();
        const interactions = createMasonryInteractions(handleReaction, vi.fn());
        const item = createFeedItem();
        const event = new MouseEvent('mousedown', { altKey: true, button: 1 });

        interactions.handleAltClickReaction(event, item, 3);

        expect(handleReaction).toHaveBeenCalledWith(item, 'like', 3);
    });
});
