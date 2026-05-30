import { createAtlasApiHeaders, createAtlasFetchAuthOptions } from './atlas-auth';

export type AtlasCheckQueueResponse<Result> = {
    ok: boolean;
    status: number;
    payload: Result;
};

export type QueueDefinition<Result> = {
    batchDelayMs: number;
    maxBatchSize: number;
    maxPendingItems: number;
    maxResultCacheEntries: number;
    maxHashCacheEntries: number;
    cacheTtlMs: number;
    endpointPath: string;
    extendBatchWindowOnNewItem?: boolean;
    buildRequestItem: (requestId: string, entry: PendingQueueItem<Result>) => Record<string, string>;
    emptyPayload: () => Result;
    parsePayloadItem: (row: MatchResponseItem) => Result;
};

export type PendingQueueItem<Result> = {
    key: string;
    scopeKey: string;
    atlasDomain: string;
    apiToken: string;
    normalizedInput: string;
    inputHash: string;
    priority: number;
    queuedAt: number;
    resolve: (response: AtlasCheckQueueResponse<Result>) => void;
    promise: Promise<AtlasCheckQueueResponse<Result>>;
};

export type MatchResponseItem = {
    request_id?: unknown;
    exists?: unknown;
    reaction?: unknown;
    reacted_at?: unknown;
    downloaded_at?: unknown;
    blacklisted_at?: unknown;
};

type BatchRequestResponse<Result> = {
    ok: boolean;
    status: number;
    results: Map<string, Result>;
};

export type BackgroundAtlasCheckQueue<Result> = {
    enqueue: (request: {
        atlasDomain: string;
        apiToken: string;
        normalizedInput: string;
        bypassCache?: boolean;
        cacheOnly?: boolean;
        priority?: number;
    }) => Promise<AtlasCheckQueueResponse<Result>>;
    getCached: (request: {
        atlasDomain: string;
        apiToken: string;
        normalizedInput: string;
    }) => AtlasCheckQueueResponse<Result> | null;
    prime: (request: {
        atlasDomain: string;
        apiToken: string;
        normalizedInput: string;
        payload: Result;
    }) => void;
};

const ATLAS_CHECK_PRIORITY_BACKGROUND = 0;
const ATLAS_CHECK_PRIORITY_NORMAL = 1;
const ATLAS_CHECK_PRIORITY_ACTIVE = 2;

function stringOrNull(value: unknown): string | null {
    return typeof value === 'string' && value.trim() !== '' ? value : null;
}

function makeScopeKey(atlasDomain: string, apiToken: string): string {
    return `${atlasDomain}\u0000${apiToken}`;
}

function makeScopedKey(atlasDomain: string, apiToken: string, normalizedInput: string): string {
    return `${makeScopeKey(atlasDomain, apiToken)}\u0000${normalizedInput}`;
}

async function sha256Hex(input: string, cache: Map<string, string>, maxEntries: number): Promise<string> {
    const cached = cache.get(input);
    if (cached) {
        return cached;
    }

    const bytes = new TextEncoder().encode(input);
    const digest = await crypto.subtle.digest('SHA-256', bytes);
    const hash = Array.from(new Uint8Array(digest))
        .map((byte) => byte.toString(16).padStart(2, '0'))
        .join('');

    if (cache.size >= maxEntries) {
        const oldestKey = cache.keys().next().value as string | undefined;
        if (oldestKey !== undefined) {
            cache.delete(oldestKey);
        }
    }

    cache.set(input, hash);
    return hash;
}

function parseJsonResponse(response: Response): Promise<unknown> {
    return response.text()
        .then((bodyText) => {
            const trimmed = bodyText.trim();
            if (trimmed === '') {
                return null;
            }

            try {
                return JSON.parse(trimmed) as unknown;
            } catch {
                return bodyText;
            }
        })
        .catch(() => null);
}

export function createBackgroundAtlasCheckQueue<Result>(
    definition: QueueDefinition<Result>,
): BackgroundAtlasCheckQueue<Result> {
    const pendingByKey = new Map<string, PendingQueueItem<Result>>();
    const inFlightByKey = new Map<string, Promise<AtlasCheckQueueResponse<Result>>>();
    const resultCacheByKey = new Map<string, { response: AtlasCheckQueueResponse<Result>; cachedAt: number }>();
    const hashByInput = new Map<string, string>();
    let flushTimer: ReturnType<typeof setTimeout> | null = null;

    function responseWithEmptyPayload(status: number): AtlasCheckQueueResponse<Result> {
        return {
            ok: status < 400,
            status,
            payload: definition.emptyPayload(),
        };
    }

    function setCachedResponse(key: string, response: AtlasCheckQueueResponse<Result>): void {
        resultCacheByKey.delete(key);
        resultCacheByKey.set(key, {
            response,
            cachedAt: Date.now(),
        });

        pruneResultCache();
    }

    function pruneResultCache(): void {
        const now = Date.now();
        for (const [key, cached] of resultCacheByKey.entries()) {
            if ((now - cached.cachedAt) >= definition.cacheTtlMs) {
                resultCacheByKey.delete(key);
            }
        }

        while (resultCacheByKey.size > definition.maxResultCacheEntries) {
            const oldestKey = resultCacheByKey.keys().next().value as string | undefined;
            if (oldestKey === undefined) {
                return;
            }

            resultCacheByKey.delete(oldestKey);
        }
    }

    function resolvePendingItem(key: string, response: AtlasCheckQueueResponse<Result>): void {
        const pending = pendingByKey.get(key);
        if (!pending) {
            return;
        }

        pendingByKey.delete(key);
        pending.resolve(response);
    }

    function makeRoomForPendingItem(priority: number): boolean {
        if (pendingByKey.size < definition.maxPendingItems) {
            return true;
        }

        const candidates = Array.from(pendingByKey.values())
            .filter((item) => item.priority < priority)
            .sort((first, second) => first.priority - second.priority || first.queuedAt - second.queuedAt);

        for (const item of candidates) {
            resolvePendingItem(item.key, responseWithEmptyPayload(204));
            if (pendingByKey.size < definition.maxPendingItems) {
                return true;
            }
        }

        return false;
    }

    function getCachedResponse(key: string): AtlasCheckQueueResponse<Result> | null {
        const cached = resultCacheByKey.get(key);
        if (!cached) {
            return null;
        }

        if ((Date.now() - cached.cachedAt) >= definition.cacheTtlMs) {
            resultCacheByKey.delete(key);
            return null;
        }

        return cached.response;
    }

    function scheduleFlush(): void {
        if (flushTimer !== null) {
            if (definition.extendBatchWindowOnNewItem !== true) {
                return;
            }

            clearTimeout(flushTimer);
        }

        flushTimer = setTimeout(() => {
            flushTimer = null;
            void flushQueue();
        }, definition.batchDelayMs);
    }

    function flushImmediately(): void {
        if (flushTimer !== null) {
            clearTimeout(flushTimer);
            flushTimer = null;
        }

        void flushQueue();
    }

    async function requestBatch(batch: PendingQueueItem<Result>[]): Promise<BatchRequestResponse<Result>> {
        const first = batch[0];
        if (!first) {
            return {
                ok: true,
                status: 200,
                results: new Map<string, Result>(),
            };
        }

        const keyByRequestId = new Map<string, string>();
        const items = batch.map((entry, index) => {
            const requestId = `req-${index}`;
            keyByRequestId.set(requestId, entry.key);
            return definition.buildRequestItem(requestId, entry);
        });

        try {
            const response = await fetch(`${first.atlasDomain}${definition.endpointPath}`, {
                method: 'POST',
                headers: createAtlasApiHeaders(first.apiToken, true),
                ...createAtlasFetchAuthOptions(first.apiToken),
                body: JSON.stringify({ items }),
            });

            const payload = await parseJsonResponse(response) as { matches?: unknown } | null;
            if (!response.ok) {
                return {
                    ok: false,
                    status: response.status,
                    results: new Map<string, Result>(),
                };
            }

            const output = new Map<string, Result>();
            if (Array.isArray(payload?.matches)) {
                for (const row of payload.matches as MatchResponseItem[]) {
                    const requestId = stringOrNull(row.request_id);
                    if (requestId === null) {
                        continue;
                    }

                    const key = keyByRequestId.get(requestId);
                    if (!key) {
                        continue;
                    }

                    output.set(key, definition.parsePayloadItem(row));
                }
            }

            return {
                ok: true,
                status: response.status,
                results: output,
            };
        } catch {
            return {
                ok: false,
                status: 0,
                results: new Map<string, Result>(),
            };
        }
    }

    async function processBatch(batch: PendingQueueItem<Result>[]): Promise<void> {
        const startedAt = Date.now();
        const batchPromise = requestBatch(batch);

        for (const entry of batch) {
            const promise = batchPromise
                .then(({ ok, status, results }) => {
                    const newerCached = resultCacheByKey.get(entry.key);
                    if (newerCached && newerCached.cachedAt >= startedAt) {
                        return newerCached.response;
                    }

                    const response: AtlasCheckQueueResponse<Result> = {
                        ok,
                        status,
                        payload: results.get(entry.key) ?? definition.emptyPayload(),
                    };

                    if (ok) {
                        setCachedResponse(entry.key, response);
                    }

                    return response;
                })
                .finally(() => {
                    inFlightByKey.delete(entry.key);
                });

            inFlightByKey.set(entry.key, promise);
            void promise.then((response) => {
                entry.resolve(response);
            });
        }

        await Promise.allSettled(batch.map((entry) => inFlightByKey.get(entry.key) ?? Promise.resolve({
            ok: false,
            status: 0,
            payload: definition.emptyPayload(),
        })));
    }

    async function flushQueue(): Promise<void> {
        const batches = new Map<string, PendingQueueItem<Result>[]>();
        const keysToDelete: string[] = [];

        const pendingEntries = Array.from(pendingByKey.entries())
            .sort(([, first], [, second]) => second.priority - first.priority || first.queuedAt - second.queuedAt);

        for (const [key, item] of pendingEntries) {
            const batch = batches.get(item.scopeKey) ?? [];
            if (batch.length >= definition.maxBatchSize) {
                continue;
            }

            batch.push(item);
            batches.set(item.scopeKey, batch);
            keysToDelete.push(key);
        }

        if (keysToDelete.length === 0) {
            return;
        }

        for (const key of keysToDelete) {
            pendingByKey.delete(key);
        }

        await Promise.allSettled(Array.from(batches.values()).map((batch) => processBatch(batch)));

        if (pendingByKey.size >= definition.maxBatchSize) {
            flushImmediately();
            return;
        }

        if (pendingByKey.size > 0) {
            scheduleFlush();
        }
    }

    return {
        async enqueue(request) {
            const key = makeScopedKey(request.atlasDomain, request.apiToken, request.normalizedInput);
            const shouldBypassCache = request.bypassCache === true;
            if (!shouldBypassCache) {
                const cached = getCachedResponse(key);
                if (cached) {
                    return cached;
                }
            }

            const pending = pendingByKey.get(key);
            if (pending) {
                return pending.promise;
            }

            const inFlight = inFlightByKey.get(key);
            if (inFlight) {
                return inFlight;
            }

            if (request.cacheOnly === true) {
                return responseWithEmptyPayload(204);
            }

            const inputHash = await sha256Hex(request.normalizedInput, hashByInput, definition.maxHashCacheEntries);

            if (!shouldBypassCache) {
                const cachedAfterHash = getCachedResponse(key);
                if (cachedAfterHash) {
                    return cachedAfterHash;
                }
            }

            const pendingAfterHash = pendingByKey.get(key);
            if (pendingAfterHash) {
                return pendingAfterHash.promise;
            }

            const inFlightAfterHash = inFlightByKey.get(key);
            if (inFlightAfterHash) {
                return inFlightAfterHash;
            }

            const priority = Math.max(
                ATLAS_CHECK_PRIORITY_BACKGROUND,
                Math.min(ATLAS_CHECK_PRIORITY_ACTIVE, request.priority ?? ATLAS_CHECK_PRIORITY_NORMAL),
            );
            if (!makeRoomForPendingItem(priority)) {
                return responseWithEmptyPayload(429);
            }

            let resolvePromise: (response: AtlasCheckQueueResponse<Result>) => void = () => {};
            const promise = new Promise<AtlasCheckQueueResponse<Result>>((resolve) => {
                resolvePromise = resolve;
            });

            pendingByKey.set(key, {
                key,
                scopeKey: makeScopeKey(request.atlasDomain, request.apiToken),
                atlasDomain: request.atlasDomain,
                apiToken: request.apiToken,
                normalizedInput: request.normalizedInput,
                inputHash,
                priority,
                queuedAt: Date.now(),
                resolve: resolvePromise,
                promise,
            });

            if (pendingByKey.size >= definition.maxBatchSize) {
                flushImmediately();
            } else {
                scheduleFlush();
            }

            return promise;
        },
        getCached(request) {
            return getCachedResponse(makeScopedKey(request.atlasDomain, request.apiToken, request.normalizedInput));
        },
        prime(request) {
            const key = makeScopedKey(request.atlasDomain, request.apiToken, request.normalizedInput);
            const response: AtlasCheckQueueResponse<Result> = {
                ok: true,
                status: 200,
                payload: request.payload,
            };

            setCachedResponse(key, response);

            const pending = pendingByKey.get(key);
            if (!pending) {
                return;
            }

            pendingByKey.delete(key);
            pending.resolve(response);
        },
    };
}
