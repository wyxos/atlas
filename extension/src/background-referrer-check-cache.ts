import { getStoredOptions } from './atlas-options';
import {
    emptyReferrerCheckResult,
    getCachedGlobalReferrerCheck,
    primeGlobalReferrerCheck,
} from './background-atlas-check-queue';
import type { ProgressEvent } from './download-progress-event';
import { cleanupUrlQueryParams } from './referrer-cleanup';
import { resolveSiteCustomizationForHostname } from './site-customizations';
import type { ReferrerMatchResult } from './content/referrer-check-queue';

type ReferrerCheckCacheUpdate = {
    exists?: boolean;
    reaction?: string | null;
    reactedAt?: string | null;
    downloadedAt?: string | null;
    blacklistedAt?: string | null;
};

function stringOrNull(value: unknown): string | null {
    return typeof value === 'string' && value.trim() !== '' ? value : null;
}

function normalizeHashAwareUrl(value: string | null | undefined): string | null {
    if (typeof value !== 'string') {
        return null;
    }

    const trimmed = value.trim();
    if (trimmed === '' || !/^https?:\/\//i.test(trimmed)) {
        return null;
    }

    return trimmed;
}

function mergeReferrerResult(
    current: ReferrerMatchResult,
    update: ReferrerCheckCacheUpdate,
): ReferrerMatchResult {
    const nextReaction = update.reaction !== undefined ? stringOrNull(update.reaction) : current.reaction;
    const nextReactedAt = update.reactedAt !== undefined ? stringOrNull(update.reactedAt) : current.reactedAt;
    const nextDownloadedAt = update.downloadedAt !== undefined ? stringOrNull(update.downloadedAt) : current.downloadedAt;
    const nextBlacklistedAt = update.blacklistedAt !== undefined ? stringOrNull(update.blacklistedAt) : current.blacklistedAt;
    const hasState = nextReaction !== null || nextDownloadedAt !== null || nextBlacklistedAt !== null;

    return {
        exists: update.exists ?? (current.exists || hasState),
        reaction: nextReaction,
        reactedAt: nextReactedAt,
        downloadedAt: nextDownloadedAt,
        blacklistedAt: nextBlacklistedAt,
    };
}

function isTerminalTransferStatus(status: string | null): boolean {
    return status === 'completed' || status === 'failed' || status === 'canceled';
}

async function resolveGlobalReferrerScope(
    referrerUrl: string | null | undefined,
): Promise<{ atlasDomain: string; apiToken: string; normalizedReferrerUrl: string } | null> {
    const normalizedReferrerUrl = normalizeHashAwareUrl(referrerUrl);
    if (normalizedReferrerUrl === null) {
        return null;
    }

    try {
        const stored = await getStoredOptions();
        if (stored.apiToken === '') {
            return null;
        }

        const hostname = new URL(normalizedReferrerUrl).hostname;
        const referrerCleanerQueryParams = resolveSiteCustomizationForHostname(stored.siteCustomizations, hostname)
            ?.referrerCleaner.stripQueryParams ?? [];
        const cleanedReferrerUrl = normalizeHashAwareUrl(
            cleanupUrlQueryParams(normalizedReferrerUrl, referrerCleanerQueryParams),
        );
        if (cleanedReferrerUrl === null) {
            return null;
        }

        return {
            atlasDomain: stored.atlasDomain,
            apiToken: stored.apiToken,
            normalizedReferrerUrl: cleanedReferrerUrl,
        };
    } catch {
        return null;
    }
}

async function primeGlobalReferrerCheckCache(
    referrerUrl: string | null | undefined,
    update: ReferrerCheckCacheUpdate,
): Promise<void> {
    const scope = await resolveGlobalReferrerScope(referrerUrl);
    if (scope === null) {
        return;
    }

    const cached = getCachedGlobalReferrerCheck({
        atlasDomain: scope.atlasDomain,
        apiToken: scope.apiToken,
        normalizedReferrerUrl: scope.normalizedReferrerUrl,
    }) ?? emptyReferrerCheckResult();

    primeGlobalReferrerCheck({
        atlasDomain: scope.atlasDomain,
        apiToken: scope.apiToken,
        normalizedReferrerUrl: scope.normalizedReferrerUrl,
        payload: mergeReferrerResult(cached, update),
    });
}

async function primeGlobalReferrerCheckCacheFromProgressEvent(event: ProgressEvent): Promise<void> {
    const isLifecycleEvent = event.event === 'DownloadTransferCreated'
        || event.event === 'DownloadTransferQueued'
        || isTerminalTransferStatus(event.status);

    if (!isLifecycleEvent || !event.referrerUrl) {
        return;
    }

    await primeGlobalReferrerCheckCache(event.referrerUrl, {
        exists: true,
        reaction: event.reaction ?? undefined,
        reactedAt: event.reactedAt,
        downloadedAt: event.downloadedAt,
        blacklistedAt: event.blacklistedAt,
    });
}

export {
    primeGlobalReferrerCheckCache,
    primeGlobalReferrerCheckCacheFromProgressEvent,
};
