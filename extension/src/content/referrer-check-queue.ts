import { getStoredOptions } from '../atlas-options';
import { normalizeUrl } from './media-utils';

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

        const response = await fetch(`${stored.atlasDomain}/api/extension/referrer-checks`, {
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
    if (normalizedReferrerUrl === null) {
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

export function clearReferrerCheckCache(): void {
    resultCacheByKey.clear();
    pendingByKey.clear();
    inFlightByKey.clear();
}
