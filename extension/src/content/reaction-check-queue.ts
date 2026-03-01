import { getStoredOptions } from '../atlas-options';
import { normalizeUrl, shouldExcludeMediaOrAnchorUrl } from './media-utils';

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

        const response = await fetch(`${stored.atlasDomain}/api/extension/badges/checks`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Atlas-Api-Key': stored.apiToken,
            },
            body: JSON.stringify({ items }),
        });

        if (!response.ok) {
            return new Map();
        }

        const payload = await response.json() as { matches?: unknown };
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

export async function enqueueReactionCheck(mediaUrl: string | null): Promise<BadgeMatchResult> {
    const normalizedMediaUrl = normalizeUrl(mediaUrl);
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

    const mediaUrlHash = await sha256Hex(normalizedMediaUrl);

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
