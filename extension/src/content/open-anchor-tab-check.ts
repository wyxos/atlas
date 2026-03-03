import { isLikelyDomainRootUrl, normalizeUrl } from './media-utils';

const CACHE_TTL_MS = 20 * 1000;

const resultCache = new Map<string, { value: boolean; cachedAt: number }>();
const inFlight = new Map<string, Promise<boolean>>();

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

export function invalidateOpenTabCheckCache(urls: string[]): void {
    for (const url of urls) {
        const comparableUrl = normalizeComparableUrl(url);
        if (comparableUrl === null) {
            continue;
        }

        resultCache.delete(comparableUrl);
        inFlight.delete(comparableUrl);
    }
}
