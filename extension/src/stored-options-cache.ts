import type { StoredOptions } from './atlas-options';

type StoredOptionsCacheEntry = {
    cacheKey: string;
    options: StoredOptions;
};

type StoredOptionsLoad = {
    cacheKey: string;
    promise: Promise<StoredOptions>;
};

let cacheEntry: StoredOptionsCacheEntry | null = null;
let activeLoad: StoredOptionsLoad | null = null;
let generation = 0;

export function storedOptionsCacheKey(atlasDomain: string, apiToken: string, settingsUpdatedAt: string): string {
    return JSON.stringify({
        atlasDomain,
        apiToken,
        settingsUpdatedAt,
    });
}

export function getStoredOptionsCacheGeneration(): number {
    return generation;
}

export function getFreshStoredOptions(cacheKey: string): StoredOptions | null {
    if (cacheEntry === null || cacheEntry.cacheKey !== cacheKey) {
        return null;
    }

    return cacheEntry.options;
}

export function getStoredOptionsLoad(cacheKey: string): Promise<StoredOptions> | null {
    if (activeLoad === null || activeLoad.cacheKey !== cacheKey) {
        return null;
    }

    return activeLoad.promise;
}

export function rememberStoredOptionsLoad(cacheKey: string, promise: Promise<StoredOptions>): void {
    activeLoad = {
        cacheKey,
        promise,
    };
}

export function forgetStoredOptionsLoad(promise: Promise<StoredOptions>): void {
    if (activeLoad?.promise === promise) {
        activeLoad = null;
    }
}

export function rememberStoredOptions(cacheKey: string, options: StoredOptions): StoredOptions {
    cacheEntry = {
        cacheKey,
        options,
    };

    return options;
}

export function invalidateStoredOptionsCache(): void {
    generation += 1;
    cacheEntry = null;
    activeLoad = null;
}
