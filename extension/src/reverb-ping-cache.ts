import { createAtlasApiHeaders, createAtlasFetchAuthOptions } from './atlas-auth';

export type ReverbPingPayload = {
    reverb?: unknown;
};

export type ReverbPingResult = {
    ok: boolean;
    status: number;
    payload: ReverbPingPayload | null;
};

type ReverbPingCacheEntry = {
    cacheKey: string;
    cachedAt: number;
    result: ReverbPingResult;
};

type ReverbPingLoad = {
    cacheKey: string;
    promise: Promise<ReverbPingResult>;
};

const REVERB_PING_CACHE_TTL_MS = 5 * 60 * 1000;

let cacheEntry: ReverbPingCacheEntry | null = null;
let activeLoad: ReverbPingLoad | null = null;

function cacheKeyFor(atlasDomain: string, apiToken: string): string {
    return JSON.stringify({
        atlasDomain,
        apiToken,
    });
}

function getFreshCachedResult(cacheKey: string): ReverbPingResult | null {
    if (cacheEntry === null
        || cacheEntry.cacheKey !== cacheKey
        || Date.now() - cacheEntry.cachedAt >= REVERB_PING_CACHE_TTL_MS) {
        return null;
    }

    return cacheEntry.result;
}

function rememberResult(cacheKey: string, result: ReverbPingResult): ReverbPingResult {
    cacheEntry = {
        cacheKey,
        cachedAt: Date.now(),
        result,
    };

    return result;
}

export async function fetchCachedReverbPing(atlasDomain: string, apiToken: string): Promise<ReverbPingResult> {
    const cacheKey = cacheKeyFor(atlasDomain, apiToken);
    const cachedResult = getFreshCachedResult(cacheKey);
    if (cachedResult !== null) {
        return cachedResult;
    }

    if (activeLoad !== null && activeLoad.cacheKey === cacheKey) {
        return activeLoad.promise;
    }

    const endpoint = `${atlasDomain}/api/extension/ping`;
    const loadPromise = fetch(endpoint, {
        method: 'GET',
        headers: createAtlasApiHeaders(apiToken),
        ...createAtlasFetchAuthOptions(apiToken),
    })
        .then(async (response): Promise<ReverbPingResult> => ({
            ok: response.ok,
            status: response.status,
            payload: await response.json().catch(() => null) as ReverbPingPayload | null,
        }))
        .then((result) => rememberResult(cacheKey, result))
        .finally(() => {
            if (activeLoad?.promise === loadPromise) {
                activeLoad = null;
            }
        });

    activeLoad = {
        cacheKey,
        promise: loadPromise,
    };

    return loadPromise;
}
