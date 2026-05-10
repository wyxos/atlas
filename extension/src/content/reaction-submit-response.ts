import type { ReverbConfig } from '../reverb-client';
import type { BadgeReactionType } from './reaction-check-queue';
import type { DownloadCloseTarget } from './reaction-submit-download-targets';

export type SubmitReactionResult = {
    ok: boolean;
    reaction: BadgeReactionType | null;
    exists: boolean;
    fileId: number | null;
    blacklistedAt: string | null;
    downloadRequested: boolean;
    shouldCloseTabAfterQueue: boolean;
    downloadTransferId: number | null;
    downloadStatus: string | null;
    downloadProgressPercent: number | null;
    downloadCloseTargets: DownloadCloseTarget[];
    reverbConfig: ReverbConfig | null;
};

export function numberOrNull(value: unknown): number | null {
    if (typeof value === 'number' && Number.isFinite(value)) {
        return value;
    }

    if (typeof value !== 'string') {
        return null;
    }

    const trimmed = value.trim();
    if (trimmed === '') {
        return null;
    }

    const parsed = Number(trimmed);
    return Number.isFinite(parsed) ? parsed : null;
}

export function stringOrNull(value: unknown): string | null {
    return typeof value === 'string' && value.trim() !== '' ? value : null;
}

export function getReactionFromPayload(payload: unknown): { found: boolean; reaction: BadgeReactionType | null } {
    if (!payload || typeof payload !== 'object') {
        return { found: false, reaction: null };
    }

    const direct = (payload as Record<string, unknown>).reaction;
    if (direct === undefined) {
        return { found: false, reaction: null };
    }

    if (direct === null) {
        return { found: true, reaction: null };
    }

    if (typeof direct === 'string') {
        return { found: true, reaction: parseReactionType(direct) };
    }

    if (typeof direct === 'object') {
        return { found: true, reaction: parseReactionType((direct as Record<string, unknown>).type) };
    }

    return { found: true, reaction: null };
}

export function getExistsFromPayload(payload: unknown): boolean | null {
    if (!payload || typeof payload !== 'object') {
        return null;
    }

    const exists = (payload as Record<string, unknown>).exists;
    return typeof exists === 'boolean' ? exists : null;
}

export function getBlacklistedAtFromPayload(payload: unknown): string | null {
    if (!payload || typeof payload !== 'object') {
        return null;
    }

    const rootPayload = payload as Record<string, unknown>;
    return stringOrNull(rootPayload.blacklisted_at) ?? stringOrNull(rootPayload.blacklistedAt);
}

export function parseReverbConfig(value: unknown): ReverbConfig | null {
    if (!value || typeof value !== 'object') {
        return null;
    }

    const row = value as Record<string, unknown>;
    const scheme = row.scheme === 'http' ? 'http' : 'https';

    return {
        enabled: row.enabled === true,
        key: stringOrNull(row.key) ?? '',
        host: stringOrNull(row.host) ?? '',
        port: numberOrNull(row.port) ?? 443,
        scheme,
        channel: stringOrNull(row.channel) ?? '',
        auth: null,
    };
}

export function batchDownloadRequested(value: unknown): boolean {
    if (!value || typeof value !== 'object') {
        return false;
    }

    const batch = (value as Record<string, unknown>).batch;
    return !!batch && typeof batch === 'object' && (batch as Record<string, unknown>).download_requested === true;
}

export function batchQueuedDownloadRequested(value: unknown): boolean {
    if (!value || typeof value !== 'object') {
        return false;
    }

    const batch = (value as Record<string, unknown>).batch;
    if (!batch || typeof batch !== 'object') {
        return false;
    }

    const items = (batch as Record<string, unknown>).items;
    return Array.isArray(items) && items.some((entry) => {
        if (!entry || typeof entry !== 'object') {
            return false;
        }

        const download = (entry as Record<string, unknown>).download;
        return !!download && typeof download === 'object' && (download as Record<string, unknown>).requested === true;
    });
}

function parseReactionType(value: unknown): BadgeReactionType | null {
    return value === 'love' || value === 'like' || value === 'funny' ? value : null;
}
