/* global chrome */
import { DEFAULT_MATCH_RULES, parseStoredMatchRules, type UrlMatchRule } from './match-rules';

export const STORAGE_KEYS = {
    atlasDomain: 'atlasDomain',
    apiToken: 'apiToken',
    matchRules: 'matchRules',
} as const;

export const DEFAULT_ATLAS_DOMAIN = 'https://atlas.test';

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
