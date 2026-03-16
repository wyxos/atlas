import { getStoredOptions } from '../atlas-options';
import { getActivePageSiteCustomization } from '../page-customization-state';
import { resolveSiteCustomizationForHostname } from '../site-customizations';
import { requestAtlasViaRuntime } from '../atlas-runtime-request';
import { atlasLoggedFetch, atlasLoggedRuntimeRequest } from './atlas-request-log';
import { normalizeUrl, shouldExcludeMediaOrAnchorUrl, type MediaElement } from './media-utils';
import { applyMediaCleaner } from './media-cleaner';

export type BadgeReactionType = 'love' | 'like' | 'dislike' | 'funny';

export type BadgeMatchResult = {
    exists: boolean;
    reaction: BadgeReactionType | null;
    reactedAt: string | null;
    downloadedAt: string | null;
    blacklistedAt: string | null;
};

type MatchQueueItem = {
    key: string;
    mediaUrl: string;
    mediaUrlHash: string;
    resolve: (result: BadgeMatchResult) => void;
    promise: Promise<BadgeMatchResult>;
};

type ReactionCheckContext = {
    media?: MediaElement | null;
    candidatePageUrls?: Array<string | null | undefined>;
};

type MatchResponseItem = {
    request_id?: unknown;
    exists?: unknown;
    reaction?: unknown;
    reacted_at?: unknown;
    downloaded_at?: unknown;
    blacklisted_at?: unknown;
};

const BATCH_DELAY_MS = 10;
const RESULT_CACHE_TTL_MS = 5 * 60 * 1000;

const pendingByKey = new Map<string, MatchQueueItem>();
const inFlightByKey = new Map<string, Promise<BadgeMatchResult>>();
const resultCacheByKey = new Map<string, { result: BadgeMatchResult; cachedAt: number }>();
const urlHashByUrl = new Map<string, string>();
let flushTimer: number | null = null;

function emptyResult(): BadgeMatchResult {
    return {
        exists: false,
        reaction: null,
        reactedAt: null,
        downloadedAt: null,
        blacklistedAt: null,
    };
}

function normalizeReaction(value: unknown): BadgeReactionType | null {
    if (value === 'love' || value === 'like' || value === 'dislike' || value === 'funny') {
        return value;
    }

    return null;
}

function stringOrNull(value: unknown): string | null {
    return typeof value === 'string' && value.trim() !== '' ? value : null;
}

async function requestBatch(batch: MatchQueueItem[]): Promise<Map<string, BadgeMatchResult>> {
    const keyByRequestId = new Map<string, string>();

    try {
        const stored = await getStoredOptions();
        if (stored.apiToken === '') {
            return new Map();
        }

        const items: Array<{ request_id: string; url_hash: string }> = [];

        batch.forEach((entry, index) => {
            const requestId = `req-${index}`;
            keyByRequestId.set(requestId, entry.key);
            items.push({
                request_id: requestId,
                url_hash: entry.mediaUrlHash,
            });
        });

        const endpoint = `${stored.atlasDomain}/api/extension/badges/checks`;
        const requestPayload = { items };
        let payload: { matches?: unknown } | null = null;
        const runtimeResponse = await atlasLoggedRuntimeRequest(
            endpoint,
            'POST',
            requestPayload,
            () => requestAtlasViaRuntime({
                endpoint,
                atlasDomain: stored.atlasDomain,
                apiToken: stored.apiToken,
                method: 'POST',
                body: requestPayload,
            }),
        );
        if (runtimeResponse !== null) {
            if (!runtimeResponse.ok) {
                return new Map();
            }

            payload = runtimeResponse.payload as { matches?: unknown };
        } else {
            const response = await atlasLoggedFetch(endpoint, 'POST', requestPayload, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Atlas-Api-Key': stored.apiToken,
                },
                body: JSON.stringify(requestPayload),
            });

            if (!response.ok) {
                return new Map();
            }

            payload = await response.json() as { matches?: unknown };
        }

        if (!Array.isArray(payload.matches)) {
            return new Map();
        }

        const output = new Map<string, BadgeMatchResult>();
        for (const row of payload.matches as MatchResponseItem[]) {
            const requestId = stringOrNull(row.request_id);
            if (requestId === null) {
                continue;
            }

            const key = keyByRequestId.get(requestId);
            if (!key) {
                continue;
            }

            output.set(key, {
                exists: row.exists === true,
                reaction: normalizeReaction(row.reaction),
                reactedAt: stringOrNull(row.reacted_at),
                downloadedAt: stringOrNull(row.downloaded_at),
                blacklistedAt: stringOrNull(row.blacklisted_at),
            });
        }

        return output;
    } catch {
        return new Map();
    }
}

async function sha256Hex(input: string): Promise<string> {
    const cached = urlHashByUrl.get(input);
    if (cached) {
        return cached;
    }

    const bytes = new TextEncoder().encode(input);
    const digest = await crypto.subtle.digest('SHA-256', bytes);
    const hash = Array.from(new Uint8Array(digest))
        .map((byte) => byte.toString(16).padStart(2, '0'))
        .join('');

    urlHashByUrl.set(input, hash);
    return hash;
}

async function flushQueue(): Promise<void> {
    const batch = Array.from(pendingByKey.values());
    pendingByKey.clear();

    if (batch.length === 0) {
        return;
    }

    const responsePromise = requestBatch(batch);

    for (const entry of batch) {
        const promise = responsePromise
            .then((results) => results.get(entry.key) ?? emptyResult())
            .then((result) => {
                resultCacheByKey.set(entry.key, {
                    result,
                    cachedAt: Date.now(),
                });

                return result;
            })
            .finally(() => {
                inFlightByKey.delete(entry.key);
            });

        inFlightByKey.set(entry.key, promise);
        void promise.then((result) => {
            entry.resolve(result);
        });
    }

    await Promise.allSettled(batch.map((entry) => inFlightByKey.get(entry.key) ?? Promise.resolve(emptyResult())));
}

function scheduleFlush(): void {
    if (flushTimer !== null) {
        return;
    }

    flushTimer = window.setTimeout(() => {
        flushTimer = null;
        void flushQueue();
    }, BATCH_DELAY_MS);
}

export async function enqueueReactionCheck(
    mediaUrl: string | null,
    context: ReactionCheckContext = {},
): Promise<BadgeMatchResult> {
    let activeSiteCustomization = getActivePageSiteCustomization();
    if (activeSiteCustomization === null) {
        try {
            const stored = await getStoredOptions();
            activeSiteCustomization = resolveSiteCustomizationForHostname(stored.siteCustomizations, window.location.hostname);
        } catch {
            activeSiteCustomization = null;
        }
    }

    const mediaCleaner = activeSiteCustomization?.mediaCleaner ?? {
        stripQueryParams: [],
        rewriteRules: [],
        strategies: [],
    };
    const cleanedMediaUrl = applyMediaCleaner(mediaUrl, mediaCleaner, {
        media: context.media ?? null,
        candidatePageUrls: context.candidatePageUrls ?? [window.location.href],
    }) ?? mediaUrl;
    const normalizedMediaUrl = normalizeUrl(cleanedMediaUrl);
    if (normalizedMediaUrl === null || shouldExcludeMediaOrAnchorUrl(mediaUrl)) {
        return Promise.resolve(emptyResult());
    }

    const key = normalizedMediaUrl;
    const cached = resultCacheByKey.get(key);
    if (cached && (Date.now() - cached.cachedAt) < RESULT_CACHE_TTL_MS) {
        return Promise.resolve(cached.result);
    }

    const queued = pendingByKey.get(key);
    if (queued) {
        return queued.promise;
    }

    const inFlight = inFlightByKey.get(key);
    if (inFlight) {
        return inFlight;
    }

    let mediaUrlHash: string;
    try {
        mediaUrlHash = await sha256Hex(normalizedMediaUrl);
    } catch {
        return Promise.resolve(emptyResult());
    }

    // Re-check after the async hash step to avoid orphaning earlier promises
    // when concurrent calls enqueue the same key.
    const queuedAfterHash = pendingByKey.get(key);
    if (queuedAfterHash) {
        return queuedAfterHash.promise;
    }

    const inFlightAfterHash = inFlightByKey.get(key);
    if (inFlightAfterHash) {
        return inFlightAfterHash;
    }

    let resolver: (result: BadgeMatchResult) => void = () => {};
    const promise = new Promise<BadgeMatchResult>((resolve) => {
        resolver = resolve;
    });

    pendingByKey.set(key, {
        key,
        mediaUrl: normalizedMediaUrl,
        mediaUrlHash,
        resolve: resolver,
        promise,
    });

    scheduleFlush();
    return promise;
}
