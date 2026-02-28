import { getStoredOptions } from '../atlas-options';
import { resolveReactionMediaUrl, type MediaElement } from './media-utils';
import type { BadgeReactionType } from './reaction-check-queue';

type SubmitReactionResult = {
    ok: boolean;
    reaction: BadgeReactionType | null;
    exists: boolean;
};

function parseReactionType(value: unknown): BadgeReactionType | null {
    if (value === 'love' || value === 'like' || value === 'dislike' || value === 'funny') {
        return value;
    }

    return null;
}

function getReactionFromPayload(payload: unknown): { found: boolean; reaction: BadgeReactionType | null } {
    if (!payload || typeof payload !== 'object') {
        return { found: false, reaction: null };
    }

    const rootPayload = payload as Record<string, unknown>;
    const direct = rootPayload.reaction;

    if (direct !== undefined) {
        if (direct === null) {
            return { found: true, reaction: null };
        }

        if (typeof direct === 'string') {
            return { found: true, reaction: parseReactionType(direct) };
        }

        if (typeof direct === 'object' && direct !== null) {
            const typed = parseReactionType((direct as Record<string, unknown>).type);
            return { found: true, reaction: typed };
        }
    }

    return { found: false, reaction: null };
}

function getExistsFromPayload(payload: unknown): boolean | null {
    if (!payload || typeof payload !== 'object') {
        return null;
    }

    const exists = (payload as Record<string, unknown>).exists;
    return typeof exists === 'boolean' ? exists : null;
}

export async function submitBadgeReaction(
    media: MediaElement,
    reactionType: BadgeReactionType,
): Promise<SubmitReactionResult> {
    const mediaUrl = resolveReactionMediaUrl(media);
    if (mediaUrl === null) {
        return { ok: false, reaction: null, exists: false };
    }

    try {
        const stored = await getStoredOptions();
        if (stored.apiToken === '') {
            return { ok: false, reaction: null, exists: false };
        }

        const response = await fetch(`${stored.atlasDomain}/api/extension/reactions`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Atlas-Api-Key': stored.apiToken,
            },
            body: JSON.stringify({
                type: reactionType,
                url: mediaUrl,
                referrer_url_hash_aware: window.location.href,
            }),
        });

        if (!response.ok) {
            return { ok: false, reaction: null, exists: false };
        }

        let payload: unknown = null;
        try {
            payload = await response.json();
        } catch {
            payload = null;
        }

        const extractedReaction = getReactionFromPayload(payload);
        const extractedExists = getExistsFromPayload(payload);

        if (extractedReaction.found) {
            return {
                ok: true,
                reaction: extractedReaction.reaction,
                exists: extractedExists ?? true,
            };
        }

        return {
            ok: true,
            reaction: reactionType,
            exists: extractedExists ?? true,
        };
    } catch {
        return { ok: false, reaction: null, exists: false };
    }
}
