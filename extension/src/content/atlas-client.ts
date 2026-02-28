import { getContentStoredOptions } from './storage';
import type { UrlMatchRule } from '../match-rules';
import type { ExtensionMatchResult, MediaCandidatePayload } from './types';

export async function fetchExtensionMatches(
    atlasDomain: string,
    apiToken: string,
    items: MediaCandidatePayload[],
): Promise<Map<string, ExtensionMatchResult>> {
    if (items.length === 0) {
        return new Map();
    }

    if (apiToken === '') {
        return new Map();
    }

    const response = await fetch(`${atlasDomain}/api/extension/matches`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Atlas-Api-Key': apiToken,
        },
        body: JSON.stringify({ items }),
    });

    if (!response.ok) {
        return new Map();
    }

    const data = (await response.json()) as { matches?: ExtensionMatchResult[] };
    const matches = Array.isArray(data.matches) ? data.matches : [];

    return new Map(matches.map((match) => [match.id, match]));
}

export async function loadContentConnectionSettings(): Promise<{
    atlasDomain: string;
    apiToken: string;
    matchRules: UrlMatchRule[];
}> {
    return getContentStoredOptions();
}
