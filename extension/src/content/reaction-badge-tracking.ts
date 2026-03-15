import { getPersistedBadgeState, type PersistedBadgeState } from './badge-state-cache';
import type { ProgressEvent } from './download-progress-bus';
import { normalizeUrl, resolveMediaUrl, resolveReactionTargetUrl, type MediaElement } from './media-utils';

export function resolveTrackedMediaUrls(media: MediaElement, pageUrl: string | null): string[] {
    const reactionTargetUrl = resolveReactionTargetUrl(media, pageUrl);
    const mediaUrl = normalizeUrl(resolveMediaUrl(media));
    const urls = [
        reactionTargetUrl,
        mediaUrl,
        media instanceof HTMLVideoElement ? pageUrl : null,
    ].filter((url): url is string => url !== null);

    return Array.from(new Set(urls));
}

export function resolvePersistenceUrl(lastReactionMediaUrl: string | null, trackedMediaUrls: string[]): string | null {
    return lastReactionMediaUrl ?? trackedMediaUrls[0] ?? null;
}

export function getLatestPersistedStateForTrackedUrls(trackedMediaUrls: string[]): PersistedBadgeState | null {
    let latest: PersistedBadgeState | null = null;
    for (const url of trackedMediaUrls) {
        const snapshot = getPersistedBadgeState(url);
        if (snapshot !== null && (latest === null || snapshot.updatedAt > latest.updatedAt)) {
            latest = snapshot;
        }
    }

    return latest;
}

export function matchingTrackedUrlFromProgressEvent(event: ProgressEvent, trackedMediaUrls: string[]): string | null {
    if (trackedMediaUrls.length === 0) {
        return null;
    }

    const candidate = normalizeUrl(event.sourceUrl);
    return candidate !== null && trackedMediaUrls.includes(candidate) ? candidate : null;
}
