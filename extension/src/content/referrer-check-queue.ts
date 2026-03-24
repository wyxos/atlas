import { getStoredOptions } from '../atlas-options';
import { requestQueuedReferrerCheckViaRuntime } from '../atlas-runtime-request';
import { cleanupUrlQueryParams } from '../referrer-cleanup';
import { atlasLoggedRuntimeRequest } from './atlas-request-log';
import { normalizeHashAwareUrl, shouldExcludeMediaOrAnchorUrl } from './media-utils';

export type ReferrerMatchResult = {
    exists: boolean;
    reaction: string | null;
    reactedAt: string | null;
    downloadedAt: string | null;
    blacklistedAt: string | null;
};

type ReferrerCheckCacheUpdate = {
    exists?: boolean;
    reaction?: string | null;
    reactedAt?: string | null;
    downloadedAt?: string | null;
    blacklistedAt?: string | null;
};

const CACHE_TTL_MS = 5 * 60 * 1000;

const resultCacheByKey = new Map<string, { result: ReferrerMatchResult; cachedAt: number }>();

function emptyResult(): ReferrerMatchResult {
    return {
        exists: false,
        reaction: null,
        reactedAt: null,
        downloadedAt: null,
        blacklistedAt: null,
    };
}

function stringOrNull(value: unknown): string | null {
    return typeof value === 'string' && value.trim() !== '' ? value : null;
}

function parseMatchResult(value: unknown): ReferrerMatchResult {
    if (!value || typeof value !== 'object') {
        return emptyResult();
    }

    const row = value as Record<string, unknown>;
    return {
        exists: row.exists === true,
        reaction: stringOrNull(row.reaction),
        reactedAt: stringOrNull(row.reactedAt),
        downloadedAt: stringOrNull(row.downloadedAt),
        blacklistedAt: stringOrNull(row.blacklistedAt),
    };
}

export async function enqueueReferrerCheck(
    referrerUrl: string | null,
    referrerCleanerQueryParams: string[] = [],
): Promise<ReferrerMatchResult> {
    const normalizedReferrerUrl = normalizeHashAwareUrl(cleanupUrlQueryParams(referrerUrl, referrerCleanerQueryParams));
    if (normalizedReferrerUrl === null || shouldExcludeMediaOrAnchorUrl(referrerUrl)) {
        return emptyResult();
    }

    const cached = resultCacheByKey.get(normalizedReferrerUrl);
    if (cached && (Date.now() - cached.cachedAt) < CACHE_TTL_MS) {
        return cached.result;
    }

    try {
        const stored = await getStoredOptions();
        if (stored.apiToken === '') {
            return emptyResult();
        }

        const endpoint = `${stored.atlasDomain}/api/extension/referrer-checks`;
        const runtimeResponse = await atlasLoggedRuntimeRequest(
            endpoint,
            'POST',
            { referrer_url: normalizedReferrerUrl },
            () => requestQueuedReferrerCheckViaRuntime({
                atlasDomain: stored.atlasDomain,
                apiToken: stored.apiToken,
                normalizedReferrerUrl,
            }),
        );

        if (runtimeResponse === null || !runtimeResponse.ok) {
            return emptyResult();
        }

        const result = parseMatchResult(runtimeResponse.payload);
        resultCacheByKey.set(normalizedReferrerUrl, {
            result,
            cachedAt: Date.now(),
        });

        return result;
    } catch {
        return emptyResult();
    }
}

export function upsertReferrerCheckCache(
    referrerUrl: string | null,
    update: ReferrerCheckCacheUpdate,
    referrerCleanerQueryParams: string[] = [],
): void {
    const normalizedReferrerUrl = normalizeHashAwareUrl(cleanupUrlQueryParams(referrerUrl, referrerCleanerQueryParams));
    if (normalizedReferrerUrl === null || shouldExcludeMediaOrAnchorUrl(referrerUrl)) {
        return;
    }

    const cached = resultCacheByKey.get(normalizedReferrerUrl)?.result ?? emptyResult();
    const nextReaction = update.reaction !== undefined ? stringOrNull(update.reaction) : cached.reaction;
    const nextReactedAt = update.reactedAt !== undefined ? stringOrNull(update.reactedAt) : cached.reactedAt;
    const nextDownloadedAt = update.downloadedAt !== undefined ? stringOrNull(update.downloadedAt) : cached.downloadedAt;
    const nextBlacklistedAt = update.blacklistedAt !== undefined ? stringOrNull(update.blacklistedAt) : cached.blacklistedAt;
    const hasState = nextReaction !== null
        || nextDownloadedAt !== null
        || nextBlacklistedAt !== null;
    const nextExists = update.exists ?? (cached.exists || hasState);

    resultCacheByKey.set(normalizedReferrerUrl, {
        result: {
            exists: nextExists,
            reaction: nextReaction,
            reactedAt: nextReactedAt,
            downloadedAt: nextDownloadedAt,
            blacklistedAt: nextBlacklistedAt,
        },
        cachedAt: Date.now(),
    });
}

export function getCachedReferrerCheck(
    referrerUrl: string | null,
    referrerCleanerQueryParams: string[] = [],
): ReferrerMatchResult | null {
    const normalizedReferrerUrl = normalizeHashAwareUrl(cleanupUrlQueryParams(referrerUrl, referrerCleanerQueryParams));
    if (normalizedReferrerUrl === null || shouldExcludeMediaOrAnchorUrl(referrerUrl)) {
        return null;
    }

    const cached = resultCacheByKey.get(normalizedReferrerUrl);
    if (!cached || (Date.now() - cached.cachedAt) >= CACHE_TTL_MS) {
        return null;
    }

    return cached.result;
}
