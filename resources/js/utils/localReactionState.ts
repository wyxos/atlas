import type { FeedItem } from '@/composables/useTabs';
import type { ReactionType } from '@/types/reaction';

const POSITIVE_REACTION_TYPES: ReactionType[] = ['love', 'like', 'funny'];
const REACTED_REACTION_TYPES: ReactionType[] = ['love', 'like', 'funny'];

export type LocalReactionSnapshot = {
    reaction: FeedItem['reaction'];
    auto_disliked: boolean;
    auto_dislike_rule: FeedItem['auto_dislike_rule'];
    blacklisted_at: FeedItem['blacklisted_at'];
    blacklist_reason: FeedItem['blacklist_reason'];
    blacklist_type: FeedItem['blacklist_type'];
    blacklist_rule: FeedItem['blacklist_rule'];
};

function normalizeReactionType(value: unknown): ReactionType | null {
    if (value === 'love' || value === 'like' || value === 'dislike' || value === 'funny') {
        return value;
    }

    return null;
}

function isPositiveReactionType(type: ReactionType | null): boolean {
    return type !== null && POSITIVE_REACTION_TYPES.includes(type);
}

function getBlacklistType(item: FeedItem): 'manual' | 'auto' | null {
    if (item.blacklisted_at === null || item.blacklisted_at === undefined) {
        return null;
    }

    if (item.blacklist_type === 'manual' || item.blacklist_type === 'auto') {
        return item.blacklist_type;
    }

    const reason = typeof item.blacklist_reason === 'string' ? item.blacklist_reason.trim() : '';

    return reason.length > 0 ? 'manual' : 'auto';
}

export function getOptimisticLocalReactionType(item: FeedItem, reactionType: ReactionType): ReactionType | null {
    return normalizeReactionType(item.reaction?.type) === reactionType
        ? null
        : reactionType;
}

export function createLocalReactionSnapshot(item: FeedItem): LocalReactionSnapshot {
    return {
        reaction: item.reaction ? { ...item.reaction } : null,
        auto_disliked: item.auto_disliked === true,
        auto_dislike_rule: item.auto_dislike_rule ?? null,
        blacklisted_at: item.blacklisted_at ?? null,
        blacklist_reason: item.blacklist_reason ?? null,
        blacklist_type: getBlacklistType(item),
        blacklist_rule: item.blacklist_rule ?? null,
    };
}

export function applyOptimisticLocalReactionState(
    item: FeedItem,
    reactionType: ReactionType,
): LocalReactionSnapshot {
    const snapshot = createLocalReactionSnapshot(item);
    const nextReactionType = getOptimisticLocalReactionType(item, reactionType);

    item.reaction = nextReactionType ? { type: nextReactionType } : null;

    if (!isPositiveReactionType(nextReactionType)) {
        return snapshot;
    }

    item.auto_disliked = false;
    item.auto_dislike_rule = null;
    item.blacklisted_at = null;
    item.blacklist_reason = null;
    item.blacklist_type = null;
    item.blacklist_rule = null;

    return snapshot;
}

export function restoreOptimisticLocalReactionState(
    item: FeedItem,
    snapshot: LocalReactionSnapshot,
): void {
    item.reaction = snapshot.reaction ? { ...snapshot.reaction } : null;
    item.auto_disliked = snapshot.auto_disliked;
    item.auto_dislike_rule = snapshot.auto_dislike_rule ?? null;
    item.blacklisted_at = snapshot.blacklisted_at ?? null;
    item.blacklist_reason = snapshot.blacklist_reason ?? null;
    item.blacklist_type = snapshot.blacklist_type ?? null;
    item.blacklist_rule = snapshot.blacklist_rule ?? null;
}

function matchesDownloadedFilter(item: FeedItem, value: unknown): boolean {
    if (value !== 'yes' && value !== 'no') {
        return true;
    }

    return value === 'yes' ? item.downloaded === true : item.downloaded !== true;
}

function matchesBlacklistedFilter(item: FeedItem, value: unknown): boolean {
    const isBlacklisted = item.blacklisted_at !== null && item.blacklisted_at !== undefined;

    if (value !== 'yes' && value !== 'no') {
        return true;
    }

    return value === 'yes' ? isBlacklisted : !isBlacklisted;
}

function matchesBlacklistTypeFilter(item: FeedItem, value: unknown): boolean {
    if (value !== 'manual' && value !== 'auto') {
        return true;
    }

    return getBlacklistType(item) === value;
}

function matchesAutoDislikedFilter(item: FeedItem, value: unknown): boolean {
    if (value !== 'yes' && value !== 'no') {
        return true;
    }

    return value === 'yes' ? item.auto_disliked === true : item.auto_disliked !== true;
}

function matchesPreviewedCountFilter(item: FeedItem, value: unknown): boolean {
    if (value === null || value === undefined || value === '') {
        return true;
    }

    const maxPreviewedCount = Number(value);

    if (!Number.isFinite(maxPreviewedCount)) {
        return true;
    }

    return Number(item.previewed_count ?? 0) <= maxPreviewedCount;
}

function matchesReactionFilter(item: FeedItem, filters: Record<string, unknown>): boolean {
    const reactionType = normalizeReactionType(item.reaction?.type);
    const reactionMode = filters.reaction_mode;

    if (reactionMode === 'unreacted') {
        return reactionType === null;
    }

    if (reactionMode === 'reacted') {
        return reactionType !== null && REACTED_REACTION_TYPES.includes(reactionType);
    }

    if (reactionMode !== 'types') {
        return true;
    }

    const selectedTypes = Array.isArray(filters.reaction)
        ? filters.reaction.map((value) => normalizeReactionType(value)).filter((value): value is ReactionType => value !== null)
        : [];

    if (selectedTypes.length === 0) {
        return false;
    }

    return reactionType !== null && selectedTypes.includes(reactionType);
}

function matchesModerationUnion(item: FeedItem, value: unknown): boolean {
    if (value !== 'auto_disliked_or_blacklisted_auto') {
        return true;
    }

    const reactionType = normalizeReactionType(item.reaction?.type);
    const isAutoBlacklisted = getBlacklistType(item) === 'auto';

    return (item.auto_disliked === true && reactionType === 'dislike') || isAutoBlacklisted;
}

export function matchesLocalViewFilters(
    item: FeedItem,
    filters: Record<string, unknown>,
): boolean {
    if (!matchesDownloadedFilter(item, filters.downloaded)) {
        return false;
    }

    if (!matchesPreviewedCountFilter(item, filters.max_previewed_count)) {
        return false;
    }

    if (filters.moderation_union === 'auto_disliked_or_blacklisted_auto') {
        return matchesModerationUnion(item, filters.moderation_union);
    }

    return matchesReactionFilter(item, filters)
        && matchesBlacklistedFilter(item, filters.blacklisted)
        && matchesBlacklistTypeFilter(item, filters.blacklist_type)
        && matchesAutoDislikedFilter(item, filters.auto_disliked);
}
