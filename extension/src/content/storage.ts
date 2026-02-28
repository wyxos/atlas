/* global chrome */
import { DEFAULT_MATCH_RULES, parseStoredMatchRules, type UrlMatchRule } from './match-rules';

const DEFAULT_ATLAS_DOMAIN = 'https://atlas.test';

function normalizeDomain(input: string): string {
    return input.trim().replace(/\/+$/, '');
}

export function getContentStoredOptions(): Promise<{ atlasDomain: string; apiToken: string; matchRules: UrlMatchRule[] }> {
    return new Promise((resolve, reject) => {
        chrome.storage.local.get(['atlasDomain', 'apiToken', 'matchRules'], (stored: Record<string, unknown>) => {
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
        });
    });
}
