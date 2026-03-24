import { getStoredOptions } from '../atlas-options';
import { getActivePageSiteCustomization } from '../page-customization-state';
import { resolveSiteCustomizationForHostname } from '../site-customizations';
import { requestQueuedBadgeCheckViaRuntime } from '../atlas-runtime-request';
import { atlasLoggedRuntimeRequest } from './atlas-request-log';
import { normalizeUrl, shouldExcludeMediaOrAnchorUrl, type MediaElement } from './media-utils';
import { applyMediaCleaner } from './media-cleaner';

export type BadgeReactionType = 'love' | 'like' | 'dislike' | 'funny';

export type BadgeMatchResult = {
    exists: boolean;
    reaction: BadgeReactionType | null;
    reactedAt: string | null;
    downloadedAt: string | null;
    blacklistedAt: string | null;
};

type ReactionCheckContext = {
    media?: MediaElement | null;
    candidatePageUrls?: Array<string | null | undefined>;
};

function emptyResult(): BadgeMatchResult {
    return {
        exists: false,
        reaction: null,
        reactedAt: null,
        downloadedAt: null,
        blacklistedAt: null,
    };
}

function normalizeReaction(value: unknown): BadgeReactionType | null {
    return value === 'love' || value === 'like' || value === 'dislike' || value === 'funny'
        ? value
        : null;
}

function stringOrNull(value: unknown): string | null {
    return typeof value === 'string' && value.trim() !== '' ? value : null;
}

function parseMatchResult(value: unknown): BadgeMatchResult {
    if (!value || typeof value !== 'object') {
        return emptyResult();
    }

    const row = value as Record<string, unknown>;
    return {
        exists: row.exists === true,
        reaction: normalizeReaction(row.reaction),
        reactedAt: stringOrNull(row.reactedAt),
        downloadedAt: stringOrNull(row.downloadedAt),
        blacklistedAt: stringOrNull(row.blacklistedAt),
    };
}

export async function enqueueReactionCheck(
    mediaUrl: string | null,
    context: ReactionCheckContext = {},
): Promise<BadgeMatchResult> {
    let activeSiteCustomization = getActivePageSiteCustomization();
    if (activeSiteCustomization === null) {
        try {
            const stored = await getStoredOptions();
            activeSiteCustomization = resolveSiteCustomizationForHostname(stored.siteCustomizations, window.location.hostname);
        } catch {
            activeSiteCustomization = null;
        }
    }

    const mediaCleaner = activeSiteCustomization?.mediaCleaner ?? {
        stripQueryParams: [],
        rewriteRules: [],
        strategies: [],
    };
    const cleanedMediaUrl = applyMediaCleaner(mediaUrl, mediaCleaner, {
        media: context.media ?? null,
        candidatePageUrls: context.candidatePageUrls ?? [window.location.href],
    }) ?? mediaUrl;
    const normalizedMediaUrl = normalizeUrl(cleanedMediaUrl);
    if (normalizedMediaUrl === null || shouldExcludeMediaOrAnchorUrl(mediaUrl)) {
        return emptyResult();
    }

    try {
        const stored = await getStoredOptions();
        if (stored.apiToken === '') {
            return emptyResult();
        }

        const endpoint = `${stored.atlasDomain}/api/extension/badges/checks`;
        const runtimeResponse = await atlasLoggedRuntimeRequest(
            endpoint,
            'POST',
            { media_url: normalizedMediaUrl },
            () => requestQueuedBadgeCheckViaRuntime({
                atlasDomain: stored.atlasDomain,
                apiToken: stored.apiToken,
                normalizedMediaUrl,
            }),
        );

        if (runtimeResponse === null || !runtimeResponse.ok) {
            return emptyResult();
        }

        return parseMatchResult(runtimeResponse.payload);
    } catch {
        return emptyResult();
    }
}
