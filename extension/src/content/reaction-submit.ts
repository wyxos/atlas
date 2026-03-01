import { getStoredOptions } from '../atlas-options';
import { normalizeUrl, resolveReactionMediaUrl, type MediaElement } from './media-utils';
import type { BadgeReactionType } from './reaction-check-queue';
import type { ReverbConfig } from '../reverb-client';

type SubmitReactionResult = {
    ok: boolean;
    reaction: BadgeReactionType | null;
    exists: boolean;
    fileId: number | null;
    downloadRequested: boolean;
    downloadTransferId: number | null;
    downloadStatus: string | null;
    downloadProgressPercent: number | null;
    reverbConfig: ReverbConfig | null;
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

function numberOrNull(value: unknown): number | null {
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

function stringOrNull(value: unknown): string | null {
    return typeof value === 'string' && value.trim() !== '' ? value : null;
}

function parseReverbConfig(value: unknown): ReverbConfig | null {
    if (!value || typeof value !== 'object') {
        return null;
    }

    const row = value as Record<string, unknown>;
    const enabled = row.enabled === true;
    const key = stringOrNull(row.key) ?? '';
    const host = stringOrNull(row.host) ?? '';
    const channel = stringOrNull(row.channel) ?? '';
    const scheme = row.scheme === 'http' ? 'http' : 'https';
    const port = numberOrNull(row.port) ?? 443;

    return {
        enabled,
        key,
        host,
        port,
        scheme,
        channel,
    };
}

export async function submitBadgeReaction(
    media: MediaElement,
    reactionType: BadgeReactionType,
): Promise<SubmitReactionResult> {
    const mediaUrl = resolveReactionMediaUrl(media);
    const pageUrl = normalizeUrl(window.location.href);
    const isVideo = media instanceof HTMLVideoElement;
    // Some platforms expose media as blob: URLs in DOM. Use page URL fallback for video reactions.
    const reactionUrl = mediaUrl ?? (isVideo ? pageUrl : null);
    if (reactionUrl === null) {
        return {
            ok: false,
            reaction: null,
            exists: false,
            fileId: null,
            downloadRequested: false,
            downloadTransferId: null,
            downloadStatus: null,
            downloadProgressPercent: null,
            reverbConfig: null,
        };
    }

    try {
        const stored = await getStoredOptions();
        if (stored.apiToken === '') {
            return {
                ok: false,
                reaction: null,
                exists: false,
                fileId: null,
                downloadRequested: false,
                downloadTransferId: null,
                downloadStatus: null,
                downloadProgressPercent: null,
                reverbConfig: null,
            };
        }

        const response = await fetch(`${stored.atlasDomain}/api/extension/reactions`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Atlas-Api-Key': stored.apiToken,
            },
            body: JSON.stringify({
                type: reactionType,
                url: reactionUrl,
                referrer_url_hash_aware: window.location.href,
                page_url: window.location.href,
                tag_name: isVideo ? 'video' : 'img',
            }),
        });

        if (!response.ok) {
            return {
                ok: false,
                reaction: null,
                exists: false,
                fileId: null,
                downloadRequested: false,
                downloadTransferId: null,
                downloadStatus: null,
                downloadProgressPercent: null,
                reverbConfig: null,
            };
        }

        let payload: unknown = null;
        try {
            payload = await response.json();
        } catch {
            payload = null;
        }

        const extractedReaction = getReactionFromPayload(payload);
        const extractedExists = getExistsFromPayload(payload);
        const rootPayload = payload && typeof payload === 'object' ? payload as Record<string, unknown> : {};
        const downloadPayload = rootPayload.download && typeof rootPayload.download === 'object'
            ? rootPayload.download as Record<string, unknown>
            : {};
        const filePayload = rootPayload.file && typeof rootPayload.file === 'object'
            ? rootPayload.file as Record<string, unknown>
            : {};
        const fileId = numberOrNull(filePayload.id);
        const downloadRequested = downloadPayload.requested === true;
        const downloadTransferId = numberOrNull(downloadPayload.transfer_id);
        const downloadStatus = stringOrNull(downloadPayload.status);
        const downloadProgressPercent = numberOrNull(downloadPayload.progress_percent);
        const reverbConfig = parseReverbConfig(rootPayload.reverb);

        if (extractedReaction.found) {
            return {
                ok: true,
                reaction: extractedReaction.reaction,
                exists: extractedExists ?? true,
                fileId,
                downloadRequested,
                downloadTransferId,
                downloadStatus,
                downloadProgressPercent,
                reverbConfig,
            };
        }

        return {
            ok: true,
            reaction: reactionType,
            exists: extractedExists ?? true,
            fileId,
            downloadRequested,
            downloadTransferId,
            downloadStatus,
            downloadProgressPercent,
            reverbConfig,
        };
    } catch {
        return {
            ok: false,
            reaction: null,
            exists: false,
            fileId: null,
            downloadRequested: false,
            downloadTransferId: null,
            downloadStatus: null,
            downloadProgressPercent: null,
            reverbConfig: null,
        };
    }
}
