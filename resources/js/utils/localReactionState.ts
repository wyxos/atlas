import type { FeedItem } from '@/composables/useTabs';
import { FEED_REMOVED_PREVIEW_COUNT } from '@/lib/feedModeration';
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
    auto_blacklisted: boolean;
    auto_blacklist_rule: FeedItem['auto_blacklist_rule'];
    auto_blacklist_containers: FeedItem['auto_blacklist_containers'];
    blacklisted_at: FeedItem['blacklisted_at'];
    blacklist_rule: FeedItem['blacklist_rule'];
    previewed_count: FeedItem['previewed_count'];
};

export function normalizeReactionType(value: unknown): ReactionType | null {
    if (value === 'love' || value === 'like' || value === 'funny') {
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

export function getOptimisticLocalReactionType(item: FeedItem, reactionType: ReactionType): ReactionType | null {
    const currentReactionType = normalizeReactionType(item.reaction?.type);

    if (currentReactionType !== reactionType) {
        return reactionType;
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
        auto_blacklisted: item.auto_blacklisted === true,
        auto_blacklist_rule: item.auto_blacklist_rule ?? null,
        auto_blacklist_containers: item.auto_blacklist_containers ? [...item.auto_blacklist_containers] : [],
        blacklisted_at: item.blacklisted_at ?? null,
        blacklist_rule: item.blacklist_rule ?? null,
        previewed_count: item.previewed_count,
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

    item.auto_blacklisted = false;
    item.auto_blacklist_rule = null;
    item.blacklisted_at = null;
    item.blacklist_rule = null;

    return snapshot;
}

export function applyExactLocalReactionState(
    item: FeedItem,
    reactionType: ReactionType,
): void {
    item.reaction = { type: reactionType };

    item.auto_blacklisted = false;
    item.auto_blacklist_rule = null;
    item.blacklisted_at = null;
    item.blacklist_rule = null;
}

export function applyOptimisticLocalBlacklistState(item: FeedItem): LocalReactionSnapshot {
    const snapshot = createLocalReactionSnapshot(item);

    item.reaction = null;
    item.auto_blacklisted = false;
    item.auto_blacklist_rule = null;
    item.blacklisted_at = item.blacklisted_at ?? new Date().toISOString();
    item.blacklist_rule = null;
    item.previewed_count = Math.max(
        Number(item.previewed_count ?? 0),
        FEED_REMOVED_PREVIEW_COUNT,
    );

    return snapshot;
}

export function applyOptimisticLocalBlacklistClearState(item: FeedItem): LocalReactionSnapshot {
    const snapshot = createLocalReactionSnapshot(item);

    item.auto_blacklisted = false;
    item.auto_blacklist_rule = null;
    item.auto_blacklist_containers = [];
    item.blacklisted_at = null;
    item.blacklist_rule = null;

    return snapshot;
}

export function applyConfirmedLocalBlacklistState(
    item: FeedItem,
    result: { blacklisted_at: string; previewed_count?: number },
): void {
    item.blacklisted_at = result.blacklisted_at;
    item.blacklist_rule = null;
    item.reaction = null;
    item.auto_blacklisted = false;
    item.auto_blacklist_rule = null;
    item.previewed_count = Math.max(
        Number(item.previewed_count ?? 0),
        Number(result.previewed_count ?? 0),
        FEED_REMOVED_PREVIEW_COUNT,
    );
}

export function applyConfirmedLocalBlacklistClearState(
    item: FeedItem,
    result: { blacklisted_at: string | null; auto_blacklisted: boolean; previewed_count?: number },
): void {
    item.auto_blacklisted = result.auto_blacklisted;
    item.auto_blacklist_rule = null;
    item.auto_blacklist_containers = [];
    item.blacklisted_at = result.blacklisted_at;
    item.blacklist_rule = null;

    if (typeof result.previewed_count === 'number') {
        item.previewed_count = result.previewed_count;
    }
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
    item.auto_blacklisted = snapshot.auto_blacklisted;
    item.auto_blacklist_rule = snapshot.auto_blacklist_rule ?? null;
    item.auto_blacklist_containers = snapshot.auto_blacklist_containers ? [...snapshot.auto_blacklist_containers] : [];
    item.blacklisted_at = snapshot.blacklisted_at ?? null;
    item.blacklist_rule = snapshot.blacklist_rule ?? null;
    item.previewed_count = snapshot.previewed_count;
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

function matchesAutoBlacklistedFilter(item: FeedItem, value: unknown): boolean {
    if (value !== 'yes' && value !== 'no') {
        return true;
    }

    return value === 'yes' ? item.auto_blacklisted === true : item.auto_blacklisted !== true;
}

function matchesMaxPreviewedCountFilter(item: FeedItem, value: unknown): boolean {
    if (value === null || value === undefined || value === '') {
        return true;
    }

    const maxPreviewedCount = Number(value);

    if (!Number.isFinite(maxPreviewedCount)) {
        return true;
    }

    return Number(item.previewed_count ?? 0) <= maxPreviewedCount;
}

function matchesMinPreviewedCountFilter(item: FeedItem, value: unknown): boolean {
    if (value === null || value === undefined || value === '') {
        return true;
    }

    const minPreviewedCount = Number(value);

    if (!Number.isFinite(minPreviewedCount)) {
        return true;
    }

    return Number(item.previewed_count ?? 0) >= minPreviewedCount;
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

    if (!matchesMaxPreviewedCountFilter(item, filters.max_previewed_count)) {
        return false;
    }

    if (!matchesMinPreviewedCountFilter(item, filters.min_previewed_count)) {
        return false;
    }

    return matchesReactionFilter(item, filters)
        && matchesBlacklistedFilter(item, filters.blacklisted)
        && matchesAutoBlacklistedFilter(item, filters.auto_blacklisted);
}
