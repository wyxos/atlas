import { describe, expect, it } from 'vitest';
import type { FeedItem } from '@/composables/useTabs';
import {
    applyOptimisticLocalReactionState,
    matchesLocalViewFilters,
    restoreOptimisticLocalReactionState,
} from './localReactionState';

function createItem(overrides: Partial<FeedItem> = {}): FeedItem {
    return {
        id: 1,
        width: 100,
        height: 100,
        page: 1,
        key: '1-1',
        index: 0,
        src: 'https://example.com/preview.jpg',
        reaction: null,
        previewed_count: 0,
        auto_disliked: false,
        blacklisted_at: null,
        blacklist_reason: null,
        blacklist_type: null,
        downloaded: false,
        ...overrides,
    };
}

describe('localReactionState', () => {
    it('clears moderation flags for optimistic positive reactions and restores them on rollback', () => {
        const item = createItem({
            reaction: { type: 'dislike' },
            auto_disliked: true,
            blacklisted_at: '2026-03-19T00:00:00Z',
            blacklist_type: 'auto',
        });

        const snapshot = applyOptimisticLocalReactionState(item, 'love');

        expect(item.reaction).toEqual({ type: 'love' });
        expect(item.auto_disliked).toBe(false);
        expect(item.blacklisted_at).toBeNull();
        expect(item.blacklist_type).toBeNull();

        restoreOptimisticLocalReactionState(item, snapshot);

        expect(item.reaction).toEqual({ type: 'dislike' });
        expect(item.auto_disliked).toBe(true);
        expect(item.blacklisted_at).toBe('2026-03-19T00:00:00Z');
        expect(item.blacklist_type).toBe('auto');
    });

    it('drops optimistic favorites from the auto-blacklisted preset', () => {
        const item = createItem({
            reaction: { type: 'dislike' },
            auto_disliked: true,
            blacklisted_at: '2026-03-19T00:00:00Z',
            blacklist_type: 'auto',
        });

        applyOptimisticLocalReactionState(item, 'love');

        expect(matchesLocalViewFilters(item, {
            blacklisted: 'yes',
            blacklist_type: 'auto',
            auto_disliked: 'any',
            reaction_mode: 'any',
        })).toBe(false);
    });

    it('keeps only love reactions in favorite presets', () => {
        const favoriteFilters = {
            reaction_mode: 'types',
            reaction: ['love'],
            blacklisted: 'no',
            blacklist_type: 'any',
            auto_disliked: 'no',
        };

        expect(matchesLocalViewFilters(createItem({
            reaction: { type: 'love' },
        }), favoriteFilters)).toBe(true);

        expect(matchesLocalViewFilters(createItem({
            reaction: { type: 'like' },
        }), favoriteFilters)).toBe(false);
    });
});
