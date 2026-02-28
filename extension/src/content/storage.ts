/* global chrome */
const DEFAULT_ATLAS_DOMAIN = 'https://atlas.test';

export type ContentMatchRule = {
    domain: string;
    regexes: string[];
};

function normalizeDomain(input: string): string {
    return input.trim().replace(/\/+$/, '');
}

export function getContentStoredOptions(): Promise<{ atlasDomain: string; apiToken: string; matchRules: ContentMatchRule[] }> {
    return new Promise((resolve, reject) => {
        chrome.storage.local.get(['atlasDomain', 'apiToken', 'matchRules'], (stored: Record<string, unknown>) => {
            if (chrome.runtime.lastError) {
                reject(new Error(chrome.runtime.lastError.message));
                return;
            }

            const storedDomain = typeof stored.atlasDomain === 'string' ? normalizeDomain(stored.atlasDomain) : '';
            const atlasDomain = storedDomain !== '' ? storedDomain : DEFAULT_ATLAS_DOMAIN;
            const apiToken = typeof stored.apiToken === 'string' ? stored.apiToken.trim() : '';
            const matchRules = parseMatchRules(stored.matchRules);

            resolve({ atlasDomain, apiToken, matchRules });
        });
    });
}

function parseMatchRules(value: unknown): ContentMatchRule[] {
    if (!Array.isArray(value)) {
        return [];
    }

    return value
        .map((entry): ContentMatchRule | null => {
            if (typeof entry !== 'object' || entry === null) {
                return null;
            }

            const domain = typeof (entry as { domain?: unknown }).domain === 'string'
                ? ((entry as { domain: string }).domain).trim().toLowerCase()
                : '';

            const regexes = Array.isArray((entry as { regexes?: unknown }).regexes)
                ? ((entry as { regexes: unknown[] }).regexes)
                    .filter((regex): regex is string => typeof regex === 'string')
                    .map((regex) => regex.trim())
                    .filter((regex) => regex !== '')
                : [];

            if (domain === '' || regexes.length === 0) {
                return null;
            }

            return { domain, regexes };
        })
        .filter((entry): entry is ContentMatchRule => entry !== null);
}
