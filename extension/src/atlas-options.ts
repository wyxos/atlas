/* global chrome */
import { DEFAULT_MATCH_RULES, parseStoredMatchRules, type UrlMatchRule } from './match-rules';

export const STORAGE_KEYS = {
    atlasDomain: 'atlasDomain',
    apiToken: 'apiToken',
    matchRules: 'matchRules',
    closeTabAfterQueueByDomain: 'closeTabAfterQueueByDomain',
    reactAllItemsInPostEnabled: 'reactAllItemsInPostEnabled',
} as const;

export const DEFAULT_ATLAS_DOMAIN = 'https://atlas.test';
export type CloseTabAfterQueueByDomain = Record<string, boolean>;

function parseStoredBoolean(value: unknown): boolean {
    return value === true;
}

export function normalizeDomain(input: string): string {
    return input.trim().replace(/\/+$/, '');
}

export function validateDomain(input: string): string | null {
    if (input === '') {
        return 'Atlas domain is required.';
    }

    if (!/^https?:\/\//i.test(input)) {
        return 'Atlas domain must start with http:// or https://.';
    }

    try {
        new URL(input);
    } catch {
        return 'Atlas domain is not a valid URL.';
    }

    return null;
}

export function getStoredOptions(): Promise<{ atlasDomain: string; apiToken: string; matchRules: UrlMatchRule[] }> {
    return new Promise((resolve, reject) => {
        chrome.storage.local.get(
            [STORAGE_KEYS.atlasDomain, STORAGE_KEYS.apiToken, STORAGE_KEYS.matchRules],
            (stored: Record<string, unknown>) => {
                if (chrome.runtime.lastError) {
                    reject(new Error(chrome.runtime.lastError.message));
                    return;
                }

                const storedDomain = typeof stored.atlasDomain === 'string' ? normalizeDomain(stored.atlasDomain) : '';
                const atlasDomain = storedDomain !== '' ? storedDomain : DEFAULT_ATLAS_DOMAIN;
                const apiToken = typeof stored.apiToken === 'string' ? stored.apiToken.trim() : '';
                const matchRules = parseStoredMatchRules(stored.matchRules);

                resolve({
                    atlasDomain,
                    apiToken,
                    matchRules: matchRules.length > 0 ? matchRules : DEFAULT_MATCH_RULES,
                });
            },
        );
    });
}

export function saveStoredOptions(atlasDomain: string, apiToken: string, matchRules: UrlMatchRule[]): Promise<void> {
    return new Promise((resolve, reject) => {
        chrome.storage.local.set(
            {
                [STORAGE_KEYS.atlasDomain]: atlasDomain,
                [STORAGE_KEYS.apiToken]: apiToken.trim(),
                [STORAGE_KEYS.matchRules]: matchRules,
            },
            () => {
                if (chrome.runtime.lastError) {
                    reject(new Error(chrome.runtime.lastError.message));
                    return;
                }

                resolve();
            },
        );
    });
}

function normalizePreferenceDomainKey(input: string): string {
    const trimmed = input.trim().toLowerCase();
    if (trimmed === '') {
        return '';
    }

    if (trimmed.includes('://')) {
        try {
            return new URL(trimmed).hostname.toLowerCase();
        } catch {
            return '';
        }
    }

    return trimmed.replace(/^\.+/, '').replace(/\.+$/, '');
}

function parseStoredCloseTabAfterQueueByDomain(value: unknown): CloseTabAfterQueueByDomain {
    if (!value || typeof value !== 'object') {
        return {};
    }

    const parsed: CloseTabAfterQueueByDomain = {};
    for (const [rawKey, rawValue] of Object.entries(value as Record<string, unknown>)) {
        if (rawValue !== true && rawValue !== false) {
            continue;
        }

        const key = normalizePreferenceDomainKey(rawKey);
        if (key === '') {
            continue;
        }

        parsed[key] = rawValue;
    }

    return parsed;
}

export function getCloseTabAfterQueueByDomain(): Promise<CloseTabAfterQueueByDomain> {
    return new Promise((resolve, reject) => {
        chrome.storage.local.get([STORAGE_KEYS.closeTabAfterQueueByDomain], (stored: Record<string, unknown>) => {
            if (chrome.runtime.lastError) {
                reject(new Error(chrome.runtime.lastError.message));
                return;
            }

            resolve(parseStoredCloseTabAfterQueueByDomain(stored[STORAGE_KEYS.closeTabAfterQueueByDomain]));
        });
    });
}

export async function getCloseTabAfterQueuePreferenceForHostname(hostname: string): Promise<boolean> {
    const key = normalizePreferenceDomainKey(hostname);
    if (key === '') {
        return false;
    }

    const preferences = await getCloseTabAfterQueueByDomain();
    return preferences[key] === true;
}

export async function setCloseTabAfterQueuePreferenceForHostname(hostname: string, enabled: boolean): Promise<void> {
    const key = normalizePreferenceDomainKey(hostname);
    if (key === '') {
        return;
    }

    const preferences = await getCloseTabAfterQueueByDomain();
    const nextPreferences: CloseTabAfterQueueByDomain = { ...preferences };
    if (enabled) {
        nextPreferences[key] = true;
    } else {
        delete nextPreferences[key];
    }

    await new Promise<void>((resolve, reject) => {
        chrome.storage.local.set(
            {
                [STORAGE_KEYS.closeTabAfterQueueByDomain]: nextPreferences,
            },
            () => {
                if (chrome.runtime.lastError) {
                    reject(new Error(chrome.runtime.lastError.message));
                    return;
                }

                resolve();
            },
        );
    });
}

export function getReactAllItemsInPostPreference(): Promise<boolean> {
    return new Promise((resolve, reject) => {
        chrome.storage.local.get([STORAGE_KEYS.reactAllItemsInPostEnabled], (stored: Record<string, unknown>) => {
            if (chrome.runtime.lastError) {
                reject(new Error(chrome.runtime.lastError.message));
                return;
            }

            resolve(parseStoredBoolean(stored[STORAGE_KEYS.reactAllItemsInPostEnabled]));
        });
    });
}

export function setReactAllItemsInPostPreference(enabled: boolean): Promise<void> {
    return new Promise((resolve, reject) => {
        chrome.storage.local.set(
            {
                [STORAGE_KEYS.reactAllItemsInPostEnabled]: enabled,
            },
            () => {
                if (chrome.runtime.lastError) {
                    reject(new Error(chrome.runtime.lastError.message));
                    return;
                }

                resolve();
            },
        );
    });
}
