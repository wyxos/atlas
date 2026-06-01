import type { BadgeMatchResult, BadgeReactionType } from './content/reaction-check-queue';
import type { ReferrerMatchResult } from './content/referrer-check-queue';
import {
    createBackgroundAtlasCheckQueue,
    type AtlasCheckQueueResponse,
    type MatchResponseItem,
} from './background-atlas-check-queue-core';

const ATLAS_CHECK_BATCH_DELAY_MS = 700;
const ATLAS_CHECK_MAX_BATCH_SIZE = 50;
const ATLAS_CHECK_MAX_PENDING_ITEMS = 500;
const ATLAS_CHECK_MAX_RESULT_CACHE_ENTRIES = 1200;
const ATLAS_CHECK_MAX_HASH_CACHE_ENTRIES = 2400;

function emptyBadgeCheckResult(): BadgeMatchResult {
    return {
        exists: false,
        reaction: null,
        reactedAt: null,
        downloadedAt: null,
        blacklistedAt: null,
    };
}

function emptyReferrerCheckResult(): ReferrerMatchResult {
    return {
        exists: false,
        reaction: null,
        reactedAt: null,
        downloadedAt: null,
        blacklistedAt: null,
    };
}

function hasReferrerMatch(result: ReferrerMatchResult): boolean {
    return result.exists
        || result.reaction !== null
        || result.reactedAt !== null
        || result.downloadedAt !== null
        || result.blacklistedAt !== null;
}

function normalizeReaction(value: unknown): BadgeReactionType | null {
    return value === 'love' || value === 'like' || value === 'funny'
        ? value
        : null;
}

function stringOrNull(value: unknown): string | null {
    return typeof value === 'string' && value.trim() !== '' ? value : null;
}

const badgeCheckQueue = createBackgroundAtlasCheckQueue<BadgeMatchResult>({
    batchDelayMs: ATLAS_CHECK_BATCH_DELAY_MS,
    maxBatchSize: ATLAS_CHECK_MAX_BATCH_SIZE,
    maxPendingItems: ATLAS_CHECK_MAX_PENDING_ITEMS,
    maxResultCacheEntries: ATLAS_CHECK_MAX_RESULT_CACHE_ENTRIES,
    maxHashCacheEntries: ATLAS_CHECK_MAX_HASH_CACHE_ENTRIES,
    cacheTtlMs: 5 * 60 * 1000,
    endpointPath: '/api/extension/badges/checks',
    extendBatchWindowOnNewItem: true,
    buildRequestItem: (requestId, entry) => ({
        request_id: requestId,
        url_hash: entry.inputHash,
    }),
    emptyPayload: emptyBadgeCheckResult,
    parsePayloadItem: (row: MatchResponseItem) => ({
        exists: row.exists === true,
        reaction: normalizeReaction(row.reaction),
        reactedAt: stringOrNull(row.reacted_at),
        downloadedAt: stringOrNull(row.downloaded_at),
        blacklistedAt: stringOrNull(row.blacklisted_at),
    }),
});

const referrerCheckQueue = createBackgroundAtlasCheckQueue<ReferrerMatchResult>({
    batchDelayMs: ATLAS_CHECK_BATCH_DELAY_MS,
    maxBatchSize: ATLAS_CHECK_MAX_BATCH_SIZE,
    maxPendingItems: ATLAS_CHECK_MAX_PENDING_ITEMS,
    maxResultCacheEntries: ATLAS_CHECK_MAX_RESULT_CACHE_ENTRIES,
    maxHashCacheEntries: ATLAS_CHECK_MAX_HASH_CACHE_ENTRIES,
    cacheTtlMs: 5 * 60 * 1000,
    endpointPath: '/api/extension/referrer-checks',
    extendBatchWindowOnNewItem: true,
    buildRequestItem: (requestId, entry) => ({
        request_id: requestId,
        referrer_hash: entry.inputHash,
    }),
    emptyPayload: emptyReferrerCheckResult,
    parsePayloadItem: (row: MatchResponseItem) => ({
        exists: row.exists === true,
        reaction: stringOrNull(row.reaction),
        reactedAt: stringOrNull(row.reacted_at),
        downloadedAt: stringOrNull(row.downloaded_at),
        blacklistedAt: stringOrNull(row.blacklisted_at),
    }),
    shouldCachePayload: hasReferrerMatch,
});

export {
    createBackgroundAtlasCheckQueue,
    emptyBadgeCheckResult,
    emptyReferrerCheckResult,
    enqueueGlobalBadgeCheck,
    enqueueGlobalReferrerCheck,
    getCachedGlobalReferrerCheck,
    primeGlobalReferrerCheck,
};

function enqueueGlobalBadgeCheck(
    request: {
        atlasDomain: string;
        apiToken: string;
        normalizedMediaUrl: string;
        bypassCache?: boolean;
        cacheOnly?: boolean;
        priority?: number;
    },
): Promise<AtlasCheckQueueResponse<BadgeMatchResult>> {
    return badgeCheckQueue.enqueue({
        atlasDomain: request.atlasDomain,
        apiToken: request.apiToken,
        normalizedInput: request.normalizedMediaUrl,
        bypassCache: request.bypassCache,
        cacheOnly: request.cacheOnly,
        priority: request.priority,
    });
}

function enqueueGlobalReferrerCheck(
    request: {
        atlasDomain: string;
        apiToken: string;
        normalizedReferrerUrl: string;
        cacheOnly?: boolean;
        priority?: number;
    },
): Promise<AtlasCheckQueueResponse<ReferrerMatchResult>> {
    return referrerCheckQueue.enqueue({
        atlasDomain: request.atlasDomain,
        apiToken: request.apiToken,
        normalizedInput: request.normalizedReferrerUrl,
        cacheOnly: request.cacheOnly,
        priority: request.priority,
    });
}

function getCachedGlobalReferrerCheck(
    request: {
        atlasDomain: string;
        apiToken: string;
        normalizedReferrerUrl: string;
    },
): ReferrerMatchResult | null {
    return referrerCheckQueue.getCached({
        atlasDomain: request.atlasDomain,
        apiToken: request.apiToken,
        normalizedInput: request.normalizedReferrerUrl,
    })?.payload ?? null;
}

function primeGlobalReferrerCheck(
    request: {
        atlasDomain: string;
        apiToken: string;
        normalizedReferrerUrl: string;
        payload: ReferrerMatchResult;
    },
): void {
    referrerCheckQueue.prime({
        atlasDomain: request.atlasDomain,
        apiToken: request.apiToken,
        normalizedInput: request.normalizedReferrerUrl,
        payload: request.payload,
    });
}
