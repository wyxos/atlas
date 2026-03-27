import { primeGlobalReferrerCheckCache } from './background-referrer-check-cache';

export type KnownReactionType = 'love' | 'like' | 'dislike' | 'funny';

export type ReferrerReactionSyncMessage = {
    type: 'ATLAS_REFERRER_REACTION_SYNC';
    phase: 'pending' | 'settled' | 'failed';
    urls: string[];
    reaction?: KnownReactionType | null;
    reactedAt?: string | null;
    downloadedAt?: string | null;
    blacklistedAt?: string | null;
};

type BrowserTab = {
    id?: number;
};

function stringOrNull(value: unknown): string | null {
    return typeof value === 'string' && value.trim() !== '' ? value : null;
}

export function parseReactionType(value: unknown): KnownReactionType | null {
    return value === 'love' || value === 'like' || value === 'dislike' || value === 'funny'
        ? value
        : null;
}

export function extractReactionFromPayload(payload: unknown): KnownReactionType | null {
    if (!payload || typeof payload !== 'object') {
        return null;
    }

    const row = payload as Record<string, unknown>;
    const direct = parseReactionType(row.reaction);
    if (direct !== null) {
        return direct;
    }

    const nested = row.reaction;
    if (nested && typeof nested === 'object') {
        return parseReactionType((nested as Record<string, unknown>).type);
    }

    return parseReactionType(row.reaction_type);
}

export function extractPayloadString(payload: unknown, ...keys: string[]): string | null {
    if (!payload || typeof payload !== 'object') {
        return null;
    }

    const row = payload as Record<string, unknown>;
    for (const key of keys) {
        const value = stringOrNull(row[key]);
        if (value !== null) {
            return value;
        }
    }

    return null;
}

function extractReferrerUrlCandidates(value: unknown): string[] {
    if (!value || typeof value !== 'object') {
        return [];
    }

    const row = value as Record<string, unknown>;
    const referrerUrl = stringOrNull(row.referrer_url_hash_aware)
        ?? stringOrNull(row.referrer_url)
        ?? stringOrNull(row.page_url);

    return referrerUrl === null ? [] : [referrerUrl];
}

export function extractReferrerReactionUrls(body: Record<string, unknown>): string[] {
    const items = Array.isArray(body.items) ? body.items : null;
    const urls = items !== null && items.length > 0
        ? items.flatMap((item) => extractReferrerUrlCandidates(item))
        : extractReferrerUrlCandidates(body);

    return Array.from(new Set(urls));
}

export function broadcastReferrerReactionSync(
    message: ReferrerReactionSyncMessage,
    excludeTabId?: number,
): void {
    if (message.urls.length === 0) {
        return;
    }

    chrome.tabs.query({}, (tabs: BrowserTab[]) => {
        tabs.forEach((tab: BrowserTab) => {
            if (typeof tab.id !== 'number' || tab.id === excludeTabId) {
                return;
            }

            chrome.tabs.sendMessage(tab.id, message, () => {
                void chrome.runtime.lastError;
            });
        });
    });
}

export function primeSettledReferrerReactionUrls(
    urls: string[],
    reaction: KnownReactionType | null,
    reactedAt: string | null,
    downloadedAt: string | null,
    blacklistedAt: string | null,
): void {
    urls.forEach((url) => {
        void Promise.resolve(primeGlobalReferrerCheckCache(url, {
            exists: true,
            reaction,
            reactedAt,
            downloadedAt,
            blacklistedAt,
        })).catch(() => undefined);
    });
}
