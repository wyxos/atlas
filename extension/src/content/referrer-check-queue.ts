import { getStoredOptions } from '../atlas-options';
import { requestQueuedReferrerCheckViaRuntime } from '../atlas-runtime-request';
import { cleanupUrlQueryParams } from '../referrer-cleanup';
import { atlasLoggedRuntimeRequest } from './atlas-request-log';
import { normalizeHashAwareUrl, shouldExcludeMediaOrAnchorUrl } from './media-utils';
import { createCivitAiDomainAliasUrls } from '../civitai-domains';

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

function hasMatch(result: ReferrerMatchResult): boolean {
    return result.exists
        || result.reaction !== null
        || result.reactedAt !== null
        || result.downloadedAt !== null
        || result.blacklistedAt !== null;
}

function cacheResult(urls: string[], result: ReferrerMatchResult): void {
    const cachedAt = Date.now();
    urls.forEach((url) => {
        resultCacheByKey.set(url, {
            result,
            cachedAt,
        });
    });
}

function getCachedResult(urls: string[]): ReferrerMatchResult | null {
    for (const url of urls) {
        const cached = resultCacheByKey.get(url);
        if (cached && (Date.now() - cached.cachedAt) < CACHE_TTL_MS) {
            return cached.result;
        }
    }

    return null;
}

export async function enqueueReferrerCheck(
    referrerUrl: string | null,
    referrerCleanerQueryParams: string[] = [],
): Promise<ReferrerMatchResult> {
    const normalizedReferrerUrl = normalizeHashAwareUrl(cleanupUrlQueryParams(referrerUrl, referrerCleanerQueryParams));
    if (normalizedReferrerUrl === null || shouldExcludeMediaOrAnchorUrl(referrerUrl)) {
        return emptyResult();
    }

    const lookupUrls = Array.from(new Set([
        normalizedReferrerUrl,
        ...createCivitAiDomainAliasUrls(normalizedReferrerUrl),
    ]));
    const cached = getCachedResult(lookupUrls);
    if (cached !== null) {
        return cached;
    }

    try {
        const stored = await getStoredOptions();
        if (stored.apiToken === '') {
            return emptyResult();
        }

        const endpoint = `${stored.atlasDomain}/api/extension/referrer-checks`;
        for (const lookupUrl of lookupUrls) {
            const runtimeResponse = await atlasLoggedRuntimeRequest(
                endpoint,
                'POST',
                { referrer_url: lookupUrl },
                () => requestQueuedReferrerCheckViaRuntime({
                    atlasDomain: stored.atlasDomain,
                    apiToken: stored.apiToken,
                    normalizedReferrerUrl: lookupUrl,
                }),
            );

            if (runtimeResponse === null || !runtimeResponse.ok) {
                continue;
            }

            const result = parseMatchResult(runtimeResponse.payload);
            if (hasMatch(result) || lookupUrl === lookupUrls[lookupUrls.length - 1]) {
                cacheResult(lookupUrls, result);

                return result;
            }
        }

        return emptyResult();
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

    cacheResult([
        normalizedReferrerUrl,
        ...createCivitAiDomainAliasUrls(normalizedReferrerUrl),
    ], {
        exists: nextExists,
        reaction: nextReaction,
        reactedAt: nextReactedAt,
        downloadedAt: nextDownloadedAt,
        blacklistedAt: nextBlacklistedAt,
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

    return getCachedResult([
        normalizedReferrerUrl,
        ...createCivitAiDomainAliasUrls(normalizedReferrerUrl),
    ]);
}
