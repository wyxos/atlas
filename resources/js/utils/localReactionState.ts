import type { FeedItem } from '@/composables/useTabs';
import type { ReactionType } from '@/types/reaction';

const POSITIVE_REACTION_TYPES: ReactionType[] = ['love', 'like', 'funny'];
const REACTED_REACTION_TYPES: ReactionType[] = ['love', 'like', 'funny'];

export type LocalReactionSnapshot = {
    reaction: FeedItem['reaction'];
    downloaded: boolean;
    src: FeedItem['src'];
    preview: FeedItem['preview'];
    thumbnail: FeedItem['thumbnail'];
    original: FeedItem['original'];
    originalUrl: FeedItem['originalUrl'];
    auto_disliked: boolean;
    auto_dislike_rule: FeedItem['auto_dislike_rule'];
    blacklisted_at: FeedItem['blacklisted_at'];
    blacklist_rule: FeedItem['blacklist_rule'];
};

export function normalizeReactionType(value: unknown): ReactionType | null {
    if (value === 'love' || value === 'like' || value === 'dislike' || value === 'funny') {
        return value;
    }

    return null;
}

export function isPositiveReactionType(type: ReactionType | null): boolean {
    return type !== null && POSITIVE_REACTION_TYPES.includes(type);
}

function getSelectedReactionTypes(filters: Record<string, unknown>): ReactionType[] {
    if (!Array.isArray(filters.reaction)) {
        return [];
    }

    return filters.reaction
        .map((value) => normalizeReactionType(value))
        .filter((value): value is ReactionType => value !== null);
}

function hasStoredDownloadState(item: FeedItem): boolean {
    return item.downloaded === true
        || (typeof item.path === 'string' && item.path.length > 0)
        || (typeof item.preview_path === 'string' && item.preview_path.length > 0)
        || (typeof item.poster_path === 'string' && item.poster_path.length > 0);
}

function applyOptimisticDownloadedCleanup(item: FeedItem): void {
    if (!hasStoredDownloadState(item)) {
        return;
    }

    item.downloaded = false;

    if (typeof item.url !== 'string' || item.url.length === 0) {
        return;
    }

    item.original = item.url;
    item.originalUrl = item.url;

    if (item.type === 'image' || item.type === 'video') {
        item.src = item.url;
        item.preview = item.url;
        item.thumbnail = item.url;
    }
}

export function getOptimisticLocalReactionType(item: FeedItem, reactionType: ReactionType): ReactionType | null {
    const currentReactionType = normalizeReactionType(item.reaction?.type);

    if (currentReactionType !== reactionType) {
        return reactionType;
    }

    if (reactionType === 'dislike' && hasStoredDownloadState(item)) {
        return 'dislike';
    }

    return null;
}

export function createLocalReactionSnapshot(item: FeedItem): LocalReactionSnapshot {
    return {
        reaction: item.reaction ? { ...item.reaction } : null,
        downloaded: item.downloaded === true,
        src: item.src,
        preview: item.preview,
        thumbnail: item.thumbnail,
        original: item.original,
        originalUrl: item.originalUrl,
        auto_disliked: item.auto_disliked === true,
        auto_dislike_rule: item.auto_dislike_rule ?? null,
        blacklisted_at: item.blacklisted_at ?? null,
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

    if (nextReactionType === 'dislike') {
        applyOptimisticDownloadedCleanup(item);

        return snapshot;
    }

    if (!isPositiveReactionType(nextReactionType)) {
        return snapshot;
    }

    item.auto_disliked = false;
    item.auto_dislike_rule = null;
    item.blacklisted_at = null;
    item.blacklist_rule = null;

    return snapshot;
}

export function applyExactLocalReactionState(
    item: FeedItem,
    reactionType: ReactionType,
): void {
    item.reaction = { type: reactionType };

    if (reactionType === 'dislike') {
        applyOptimisticDownloadedCleanup(item);

        return;
    }

    item.auto_disliked = false;
    item.auto_dislike_rule = null;
    item.blacklisted_at = null;
    item.blacklist_rule = null;
}

export function restoreOptimisticLocalReactionState(
    item: FeedItem,
    snapshot: LocalReactionSnapshot,
): void {
    item.reaction = snapshot.reaction ? { ...snapshot.reaction } : null;
    item.downloaded = snapshot.downloaded;
    item.src = snapshot.src;
    item.preview = snapshot.preview;
    item.thumbnail = snapshot.thumbnail;
    item.original = snapshot.original;
    item.originalUrl = snapshot.originalUrl;
    item.auto_disliked = snapshot.auto_disliked;
    item.auto_dislike_rule = snapshot.auto_dislike_rule ?? null;
    item.blacklisted_at = snapshot.blacklisted_at ?? null;
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

    const selectedTypes = getSelectedReactionTypes(filters);

    if (selectedTypes.length === 0) {
        return false;
    }

    return reactionType !== null && selectedTypes.includes(reactionType);
}

export function isPositiveOnlyLocalView(filters: Record<string, unknown>): boolean {
    const reactionMode = filters.reaction_mode;

    if (reactionMode === 'reacted') {
        return true;
    }

    if (reactionMode !== 'types') {
        return false;
    }

    const selectedTypes = getSelectedReactionTypes(filters);

    return selectedTypes.length > 0 && selectedTypes.every((type) => isPositiveReactionType(type));
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

    return matchesReactionFilter(item, filters)
        && matchesBlacklistedFilter(item, filters.blacklisted)
        && matchesAutoDislikedFilter(item, filters.auto_disliked);
}
