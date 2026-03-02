import { getStoredOptions } from '../atlas-options';
import { atlasLoggedFetch } from './atlas-request-log';
import { normalizeUrl, shouldExcludeMediaOrAnchorUrl } from './media-utils';

export type ReferrerMatchResult = {
    exists: boolean;
    reaction: string | null;
    reactedAt: string | null;
    downloadedAt: string | null;
    blacklistedAt: string | null;
};

type QueueItem = {
    key: string;
    referrerUrlHash: string;
    resolve: (result: ReferrerMatchResult) => void;
    promise: Promise<ReferrerMatchResult>;
};

type MatchResponseItem = {
    request_id?: unknown;
    exists?: unknown;
    reaction?: unknown;
    reacted_at?: unknown;
    downloaded_at?: unknown;
    blacklisted_at?: unknown;
};

const BATCH_DELAY_MS = 12;
const CACHE_TTL_MS = 5 * 60 * 1000;

const pendingByKey = new Map<string, QueueItem>();
const inFlightByKey = new Map<string, Promise<ReferrerMatchResult>>();
const resultCacheByKey = new Map<string, { result: ReferrerMatchResult; cachedAt: number }>();
const hashByUrl = new Map<string, string>();
let flushTimer: number | null = null;

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

type ReferrerCheckCacheUpdate = {
    exists?: boolean;
    reaction?: string | null;
    reactedAt?: string | null;
    downloadedAt?: string | null;
    blacklistedAt?: string | null;
};

async function sha256Hex(input: string): Promise<string> {
    const cached = hashByUrl.get(input);
    if (cached) {
        return cached;
    }

    const bytes = new TextEncoder().encode(input);
    const digest = await crypto.subtle.digest('SHA-256', bytes);
    const hash = Array.from(new Uint8Array(digest))
        .map((byte) => byte.toString(16).padStart(2, '0'))
        .join('');

    hashByUrl.set(input, hash);
    return hash;
}

async function requestBatch(batch: QueueItem[]): Promise<Map<string, ReferrerMatchResult>> {
    const keyByRequestId = new Map<string, string>();

    try {
        const stored = await getStoredOptions();
        if (stored.apiToken === '') {
            return new Map();
        }

        const items: Array<{ request_id: string; referrer_hash: string }> = [];
        batch.forEach((entry, index) => {
            const requestId = `ref-${index}`;
            keyByRequestId.set(requestId, entry.key);
            items.push({
                request_id: requestId,
                referrer_hash: entry.referrerUrlHash,
            });
        });

        const endpoint = `${stored.atlasDomain}/api/extension/referrer-checks`;
        const requestPayload = { items };
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

        const payload = await response.json() as { matches?: unknown };
        if (!Array.isArray(payload.matches)) {
            return new Map();
        }

        const output = new Map<string, ReferrerMatchResult>();
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
                reaction: stringOrNull(row.reaction),
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

export async function enqueueReferrerCheck(referrerUrl: string | null): Promise<ReferrerMatchResult> {
    const normalizedReferrerUrl = normalizeUrl(referrerUrl);
    if (normalizedReferrerUrl === null || shouldExcludeMediaOrAnchorUrl(referrerUrl)) {
        return Promise.resolve(emptyResult());
    }

    const key = normalizedReferrerUrl;
    const cached = resultCacheByKey.get(key);
    if (cached && (Date.now() - cached.cachedAt) < CACHE_TTL_MS) {
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

    const referrerUrlHash = await sha256Hex(normalizedReferrerUrl);
    let resolver: (result: ReferrerMatchResult) => void = () => {};
    const promise = new Promise<ReferrerMatchResult>((resolve) => {
        resolver = resolve;
    });

    pendingByKey.set(key, {
        key,
        referrerUrlHash,
        resolve: resolver,
        promise,
    });

    scheduleFlush();
    return promise;
}

export function upsertReferrerCheckCache(referrerUrl: string | null, update: ReferrerCheckCacheUpdate): void {
    const normalizedReferrerUrl = normalizeUrl(referrerUrl);
    if (normalizedReferrerUrl === null || shouldExcludeMediaOrAnchorUrl(referrerUrl)) {
        return;
    }

    const key = normalizedReferrerUrl;
    const cached = resultCacheByKey.get(key)?.result ?? emptyResult();
    const nextReaction = update.reaction !== undefined ? stringOrNull(update.reaction) : cached.reaction;
    const nextReactedAt = update.reactedAt !== undefined ? stringOrNull(update.reactedAt) : cached.reactedAt;
    const nextDownloadedAt = update.downloadedAt !== undefined ? stringOrNull(update.downloadedAt) : cached.downloadedAt;
    const nextBlacklistedAt = update.blacklistedAt !== undefined ? stringOrNull(update.blacklistedAt) : cached.blacklistedAt;
    const hasState = nextReaction !== null
        || nextDownloadedAt !== null
        || nextBlacklistedAt !== null;
    const nextExists = update.exists ?? (cached.exists || hasState);

    const next: ReferrerMatchResult = {
        exists: nextExists,
        reaction: nextReaction,
        reactedAt: nextReactedAt,
        downloadedAt: nextDownloadedAt,
        blacklistedAt: nextBlacklistedAt,
    };

    resultCacheByKey.set(key, {
        result: next,
        cachedAt: Date.now(),
    });

    const pending = pendingByKey.get(key);
    if (pending) {
        pendingByKey.delete(key);
        pending.resolve(next);
    }
}

export function getCachedReferrerCheck(referrerUrl: string | null): ReferrerMatchResult | null {
    const normalizedReferrerUrl = normalizeUrl(referrerUrl);
    if (normalizedReferrerUrl === null || shouldExcludeMediaOrAnchorUrl(referrerUrl)) {
        return null;
    }

    const cached = resultCacheByKey.get(normalizedReferrerUrl);
    if (!cached || (Date.now() - cached.cachedAt) >= CACHE_TTL_MS) {
        return null;
    }

    return cached.result;
}
