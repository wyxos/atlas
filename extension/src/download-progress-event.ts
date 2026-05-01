import type { BadgeReactionType } from './content/reaction-check-queue';
import type { ReverbEventName } from './reverb-client';

export type ProgressEvent = {
    event: ReverbEventName;
    fileId: number | null;
    transferId: number | null;
    sourceUrl: string | null;
    referrerUrl: string | null;
    status: string | null;
    percent: number | null;
    reaction: BadgeReactionType | null;
    reactedAt: string | null | undefined;
    downloadedAt: string | null | undefined;
    blacklistedAt: string | null | undefined;
    payload: Record<string, unknown>;
};

function asNumber(value: unknown): number | null {
    if (typeof value === 'number' && Number.isFinite(value)) {
        return value;
    }

    if (typeof value === 'string') {
        const trimmed = value.trim();
        if (trimmed === '') {
            return null;
        }

        const parsed = Number(trimmed);
        return Number.isFinite(parsed) ? parsed : null;
    }

    return null;
}

function asString(value: unknown): string | null {
    return typeof value === 'string' && value.trim() !== '' ? value : null;
}

function asReaction(value: unknown): BadgeReactionType | null {
    if (value === 'love' || value === 'like' || value === 'funny') {
        return value;
    }

    return null;
}

function parseReaction(payload: Record<string, unknown>): BadgeReactionType | null {
    const direct = asReaction(payload.reaction);
    if (direct !== null) {
        return direct;
    }

    const reactionType = asReaction(payload.reactionType ?? payload.reaction_type);
    if (reactionType !== null) {
        return reactionType;
    }

    if (payload.reaction && typeof payload.reaction === 'object') {
        const nested = payload.reaction as Record<string, unknown>;
        return asReaction(nested.type);
    }

    return null;
}

function parseOptionalString(
    payload: Record<string, unknown>,
    ...keys: string[]
): string | null | undefined {
    for (const key of keys) {
        if (!(key in payload)) {
            continue;
        }

        return asString(payload[key]);
    }

    return undefined;
}

export function createProgressEvent(event: ReverbEventName, payload: Record<string, unknown>): ProgressEvent {
    return {
        event,
        fileId: asNumber(payload.fileId ?? payload.file_id),
        transferId: asNumber(payload.downloadTransferId ?? payload.id),
        sourceUrl: asString(payload.original ?? payload.url ?? payload.file_url),
        referrerUrl: asString(payload.referrer_url ?? payload.referrerUrl ?? payload.page_url),
        status: asString(payload.status),
        percent: asNumber(payload.percent),
        reaction: parseReaction(payload),
        reactedAt: parseOptionalString(payload, 'reacted_at', 'reactedAt'),
        downloadedAt: parseOptionalString(payload, 'downloaded_at', 'downloadedAt'),
        blacklistedAt: parseOptionalString(payload, 'blacklisted_at', 'blacklistedAt'),
        payload,
    };
}
