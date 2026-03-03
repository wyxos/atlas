import { isLikelyDomainRootUrl, normalizeUrl } from './media-utils';

const CACHE_TTL_MS = 20 * 1000;

const resultCache = new Map<string, { value: boolean; cachedAt: number }>();
const inFlight = new Map<string, Promise<boolean>>();
const countCache = new Map<string, { value: number; cachedAt: number }>();
const countInFlight = new Map<string, Promise<number>>();

function normalizeComparableUrl(value: string | null): string | null {
    if (isLikelyDomainRootUrl(value)) {
        return null;
    }

    const normalized = normalizeUrl(value);
    if (normalized === null) {
        return null;
    }

    try {
        const parsed = new URL(normalized);
        parsed.hash = '';
        return parsed.toString();
    } catch {
        return null;
    }
}

export function toComparableOpenTabUrl(url: string | null): string | null {
    return normalizeComparableUrl(url);
}

export async function isUrlOpenInAnotherTab(url: string | null): Promise<boolean> {
    const comparableUrl = normalizeComparableUrl(url);
    if (comparableUrl === null) {
        return false;
    }

    const cached = resultCache.get(comparableUrl);
    if (cached && (Date.now() - cached.cachedAt) < CACHE_TTL_MS) {
        return cached.value;
    }

    const existing = inFlight.get(comparableUrl);
    if (existing) {
        return existing;
    }

    const request = new Promise<boolean>((resolve) => {
        chrome.runtime.sendMessage(
            {
                type: 'ATLAS_IS_URL_OPEN',
                url: comparableUrl,
            },
            (response: unknown) => {
                const value = typeof response === 'object'
                    && response !== null
                    && (response as { isOpenInAnotherTab?: unknown }).isOpenInAnotherTab === true;
                resultCache.set(comparableUrl, {
                    value,
                    cachedAt: Date.now(),
                });
                resolve(value);
            },
        );
    }).finally(() => {
        inFlight.delete(comparableUrl);
    });

    inFlight.set(comparableUrl, request);
    return request;
}

export async function getOpenTabCountForUrl(url: string | null): Promise<number> {
    const comparableUrl = normalizeComparableUrl(url);
    if (comparableUrl === null) {
        return 0;
    }

    const cached = countCache.get(comparableUrl);
    if (cached && (Date.now() - cached.cachedAt) < CACHE_TTL_MS) {
        return cached.value;
    }

    const existing = countInFlight.get(comparableUrl);
    if (existing) {
        return existing;
    }

    const request = new Promise<number>((resolve) => {
        chrome.runtime.sendMessage(
            {
                type: 'ATLAS_GET_URL_OPEN_COUNT',
                url: comparableUrl,
            },
            (response: unknown) => {
                const countValue = typeof response === 'object'
                    && response !== null
                    && typeof (response as { count?: unknown }).count === 'number'
                    && Number.isFinite((response as { count: number }).count)
                    ? Math.max(0, Math.floor((response as { count: number }).count))
                    : 0;
                countCache.set(comparableUrl, {
                    value: countValue,
                    cachedAt: Date.now(),
                });
                resolve(countValue);
            },
        );
    }).finally(() => {
        countInFlight.delete(comparableUrl);
    });

    countInFlight.set(comparableUrl, request);
    return request;
}

export function invalidateOpenTabCheckCache(urls: string[]): void {
    for (const url of urls) {
        const comparableUrl = normalizeComparableUrl(url);
        if (comparableUrl === null) {
            continue;
        }

        resultCache.delete(comparableUrl);
        inFlight.delete(comparableUrl);
        countCache.delete(comparableUrl);
        countInFlight.delete(comparableUrl);
    }
}
