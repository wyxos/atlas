import { describe, expect, it } from 'vitest';
import type { FeedItem } from '@/composables/useTabs';
import {
    applyOptimisticLocalReactionState,
    isPositiveOnlyLocalView,
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
        });

        const snapshot = applyOptimisticLocalReactionState(item, 'love');

        expect(item.reaction).toEqual({ type: 'love' });
        expect(item.auto_disliked).toBe(false);
        expect(item.blacklisted_at).toBeNull();

        restoreOptimisticLocalReactionState(item, snapshot);

        expect(item.reaction).toEqual({ type: 'dislike' });
        expect(item.auto_disliked).toBe(true);
        expect(item.blacklisted_at).toBe('2026-03-19T00:00:00Z');
    });

    it('drops optimistic favorites from the blacklisted preset', () => {
        const item = createItem({
            reaction: { type: 'dislike' },
            auto_disliked: true,
            blacklisted_at: '2026-03-19T00:00:00Z',
        });

        applyOptimisticLocalReactionState(item, 'love');

        expect(matchesLocalViewFilters(item, {
            blacklisted: 'yes',
            auto_disliked: 'any',
            reaction_mode: 'any',
        })).toBe(false);
    });

    it('keeps only love reactions in favorite presets', () => {
        const favoriteFilters = {
            reaction_mode: 'types',
            reaction: ['love'],
            blacklisted: 'no',
            auto_disliked: 'no',
        };

        expect(matchesLocalViewFilters(createItem({
            reaction: { type: 'love' },
        }), favoriteFilters)).toBe(true);

        expect(matchesLocalViewFilters(createItem({
            reaction: { type: 'like' },
        }), favoriteFilters)).toBe(false);
    });

    it('applies preview count only when a filter explicitly sets a limit', () => {
        const item = createItem({
            reaction: { type: 'dislike' },
            previewed_count: 8,
        });

        expect(matchesLocalViewFilters(item, {
            reaction_mode: 'types',
            reaction: ['dislike'],
            blacklisted: 'no',
            auto_disliked: 'any',
            max_previewed_count: null,
        })).toBe(true);

        expect(matchesLocalViewFilters(item, {
            reaction_mode: 'types',
            reaction: ['dislike'],
            blacklisted: 'no',
            auto_disliked: 'any',
            max_previewed_count: 3,
        })).toBe(false);
    });

    it('limits blacklist review filters to previewed count three or lower', () => {
        const filters = {
            reaction_mode: 'any',
            blacklisted: 'yes',
            auto_disliked: 'any',
            max_previewed_count: 3,
        };

        expect(matchesLocalViewFilters(createItem({
            blacklisted_at: '2026-04-30T00:00:00Z',
            previewed_count: 3,
        }), filters)).toBe(true);

        expect(matchesLocalViewFilters(createItem({
            blacklisted_at: '2026-04-30T00:00:00Z',
            previewed_count: 4,
        }), filters)).toBe(false);
    });

    it('treats reacted mode as a positive-only local view', () => {
        expect(isPositiveOnlyLocalView({
            reaction_mode: 'reacted',
        })).toBe(true);
    });

    it('treats types mode as positive-only only when every selected reaction is positive', () => {
        expect(isPositiveOnlyLocalView({
            reaction_mode: 'types',
            reaction: ['love', 'funny'],
        })).toBe(true);

        expect(isPositiveOnlyLocalView({
            reaction_mode: 'types',
            reaction: ['love', 'dislike'],
        })).toBe(false);
    });
});
