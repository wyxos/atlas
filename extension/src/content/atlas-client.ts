import { getContentStoredOptions } from './storage';
import type { ExtensionMatchResult, MediaCandidatePayload } from './types';

export async function fetchExtensionMatches(items: MediaCandidatePayload[]): Promise<Map<string, ExtensionMatchResult>> {
    if (items.length === 0) {
        return new Map();
    }

    const { atlasDomain, apiToken } = await getContentStoredOptions();
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
