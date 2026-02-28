import { getStoredOptions } from '../atlas-options';
import { normalizeUrl } from './media-utils';

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

const BATCH_DELAY_MS = 24;

const pendingByKey = new Map<string, MatchQueueItem>();
const inFlightByKey = new Map<string, Promise<BadgeMatchResult>>();
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

        const items: Array<{ request_id: string; url: string }> = [];

        batch.forEach((entry, index) => {
            const requestId = `req-${index}`;
            keyByRequestId.set(requestId, entry.key);
            items.push({
                request_id: requestId,
                url: entry.mediaUrl,
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

export function enqueueReactionCheck(mediaUrl: string | null): Promise<BadgeMatchResult> {
    const normalizedMediaUrl = normalizeUrl(mediaUrl);
    if (normalizedMediaUrl === null) {
        return Promise.resolve(emptyResult());
    }

    const key = normalizedMediaUrl;
    const queued = pendingByKey.get(key);
    if (queued) {
        return queued.promise;
    }

    const inFlight = inFlightByKey.get(key);
    if (inFlight) {
        return inFlight;
    }

    let resolver: (result: BadgeMatchResult) => void = () => {};
    const promise = new Promise<BadgeMatchResult>((resolve) => {
        resolver = resolve;
    });

    pendingByKey.set(key, {
        key,
        mediaUrl: normalizedMediaUrl,
        resolve: resolver,
        promise,
    });

    scheduleFlush();
    return promise;
}
