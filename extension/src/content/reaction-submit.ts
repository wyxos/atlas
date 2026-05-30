import { getStoredOptions } from '../atlas-options';
import { createAtlasApiHeaders, createAtlasFetchAuthOptions, hasAtlasApiAuth } from '../atlas-auth';
import { getActivePageSiteCustomization } from '../page-customization-state';
import { cleanupUrlQueryParams } from '../referrer-cleanup';
import { normalizeHashAwareUrl, normalizeUrl, resolveReactionTargetUrl, type MediaElement } from './media-utils';
import type { BatchReactionItem, ListingMetadataOverrides } from './reaction-batch-types';
import type { BadgeReactionType } from './reaction-check-queue';
import { atlasLoggedFetch, atlasLoggedRuntimeRequest } from './atlas-request-log';
import { getDownloadCloseTargets } from './reaction-submit-download-targets';
import { applyMediaCleaner } from './media-cleaner';
import { resolveSiteCustomizationForHostname } from '../site-customizations';
import {
    batchDownloadRequested,
    batchQueuedDownloadRequested,
    getBlacklistedAtFromPayload,
    getExistsFromPayload,
    getReactionFromPayload,
    numberOrNull,
    parseReverbConfig,
    stringOrNull,
    type SubmitReactionResult,
} from './reaction-submit-response';

export type SubmitDownloadBehavior = 'queue' | 'skip' | 'force';

type RuntimeCookie = {
    name: string;
    value: string;
    domain: string;
    path: string;
    secure: boolean;
    http_only: boolean;
    host_only: boolean;
    expires_at: number | null;
};

type RuntimeReactionSubmitResponse = {
    ok: boolean;
    status: number;
    payload: unknown;
};

type SubmitBadgeReactionOptions = {
    batchItems?: BatchReactionItem[] | null;
    downloadBehavior?: SubmitDownloadBehavior;
    listingMetadataOverrides?: ListingMetadataOverrides | null;
    referrerUrlOverride?: string | null;
};

type BadgeSubmitType = BadgeReactionType | 'blacklist';

function normalizeCookieUrls(urls: Array<string | null>): string[] {
    const normalized = urls
        .map((url) => normalizeUrl(url))
        .filter((url): url is string => url !== null);

    return Array.from(new Set(normalized));
}

function parseRuntimeCookies(value: unknown): RuntimeCookie[] {
    if (!Array.isArray(value)) {
        return [];
    }

    const cookies: RuntimeCookie[] = [];

    for (const entry of value) {
        if (!entry || typeof entry !== 'object') {
            continue;
        }

        const row = entry as Record<string, unknown>;
        const name = stringOrNull(row.name);
        const domain = stringOrNull(row.domain);
        const pathRaw = stringOrNull(row.path) ?? '/';
        if (name === null || domain === null) {
            continue;
        }

        const path = pathRaw.startsWith('/') ? pathRaw : `/${pathRaw}`;
        const valueField = typeof row.value === 'string' ? row.value : '';
        const expiresAtRaw = numberOrNull(row.expires_at);

        cookies.push({
            name,
            value: valueField,
            domain: domain.toLowerCase(),
            path,
            secure: row.secure === true,
            http_only: row.http_only === true,
            host_only: row.host_only === true,
            expires_at: expiresAtRaw !== null ? Math.floor(expiresAtRaw) : null,
        });
    }

    return cookies;
}

async function getRuntimeCookies(urls: string[]): Promise<RuntimeCookie[]> {
    if (urls.length === 0) {
        return [];
    }

    if (typeof chrome === 'undefined' || !chrome.runtime || typeof chrome.runtime.sendMessage !== 'function') {
        return [];
    }

    return new Promise((resolve) => {
        try {
            chrome.runtime.sendMessage(
                {
                    type: 'ATLAS_GET_URL_COOKIES',
                    urls,
                },
                (response: unknown) => {
                    if (chrome.runtime.lastError) {
                        resolve([]);
                        return;
                    }

                    if (!response || typeof response !== 'object') {
                        resolve([]);
                        return;
                    }

                    const cookies = parseRuntimeCookies((response as { cookies?: unknown }).cookies);
                    resolve(cookies);
                },
            );
        } catch {
            resolve([]);
        }
    });
}

async function submitReactionViaRuntime(
    endpoint: string,
    atlasDomain: string,
    apiToken: string,
    body: Record<string, unknown>,
): Promise<RuntimeReactionSubmitResponse | null> {
    if (typeof chrome === 'undefined' || !chrome.runtime || typeof chrome.runtime.sendMessage !== 'function') {
        return null;
    }

    return new Promise((resolve) => {
        try {
            chrome.runtime.sendMessage(
                {
                    type: 'ATLAS_SUBMIT_REACTION',
                    endpoint,
                    atlasDomain,
                    apiToken,
                    body,
                },
                (response: unknown) => {
                    if (chrome.runtime.lastError) {
                        resolve(null);
                        return;
                    }

                    if (!response || typeof response !== 'object') {
                        resolve(null);
                        return;
                    }

                    const row = response as Record<string, unknown>;
                    const ok = row.ok === true;
                    const statusRaw = numberOrNull(row.status);
                    resolve({
                        ok,
                        status: statusRaw !== null ? statusRaw : 0,
                        payload: row.payload ?? null,
                    });
                },
            );
        } catch {
            resolve(null);
        }
    });
}

function getSafeUserAgent(): string | null {
    try {
        const userAgent = navigator.userAgent.trim();
        return userAgent === '' ? null : userAgent;
    } catch {
        return null;
    }
}

export async function submitBadgeReaction(
    media: MediaElement,
    reactionType: BadgeSubmitType,
    options: SubmitBadgeReactionOptions = {},
): Promise<SubmitReactionResult> {
    const pageUrl = normalizeUrl(window.location.href);
    const reactionUrl = resolveReactionTargetUrl(media, pageUrl);
    const isVideo = media instanceof HTMLVideoElement;
    const batchItems = options.batchItems?.filter((item) => item.url.trim() !== '') ?? [];
    const listingMetadataOverrides = options.listingMetadataOverrides ?? null;
    const usesBatchEndpoint = batchItems.length >= 2;
    if (reactionUrl === null && !usesBatchEndpoint) {
        return {
            ok: false,
            reaction: null,
            exists: false,
            fileId: null,
            blacklistedAt: null,
            downloadRequested: false,
            shouldCloseTabAfterQueue: false,
            downloadTransferId: null,
            downloadStatus: null,
            downloadProgressPercent: null,
            downloadCloseTargets: [],
            reverbConfig: null,
        };
    }

    try {
        const stored = await getStoredOptions();
        if (!hasAtlasApiAuth(stored.atlasDomain, stored.apiToken)) {
            return {
                ok: false,
                reaction: null,
                exists: false,
                fileId: null,
                blacklistedAt: null,
                downloadRequested: false,
                shouldCloseTabAfterQueue: false,
                downloadTransferId: null,
                downloadStatus: null,
                downloadProgressPercent: null,
                downloadCloseTargets: [],
                reverbConfig: null,
            };
        }

        const rawPageReferrerUrl = normalizeHashAwareUrl(options.referrerUrlOverride) ?? window.location.href;
        const cookieUrls = usesBatchEndpoint
            ? normalizeCookieUrls([
                ...batchItems.map((item) => item.url),
                ...batchItems.map((item) => item.pageUrl),
                ...batchItems.map((item) => item.referrerUrlHashAware),
            ])
            : normalizeCookieUrls([reactionUrl, rawPageReferrerUrl, pageUrl]);
        const cookies = reactionType === 'blacklist' ? [] : await getRuntimeCookies(cookieUrls);
        const userAgent = getSafeUserAgent();
        const siteCustomization = getActivePageSiteCustomization()
            ?? resolveSiteCustomizationForHostname(stored.siteCustomizations, window.location.hostname);
        const referrerCleanerQueryParams = siteCustomization?.referrerCleaner.stripQueryParams ?? [];
        const mediaCleaner = siteCustomization?.mediaCleaner ?? {
            stripQueryParams: [],
            rewriteRules: [],
            strategies: [],
        };
        const cleanedPageReferrerUrl = cleanupUrlQueryParams(rawPageReferrerUrl, referrerCleanerQueryParams)
            ?? rawPageReferrerUrl;
        const cleanedReactionUrl = usesBatchEndpoint
            ? reactionUrl
            : applyMediaCleaner(reactionUrl, mediaCleaner, {
                media,
                candidatePageUrls: [rawPageReferrerUrl, window.location.href],
            }) ?? reactionUrl;
        const requestBody = usesBatchEndpoint
            ? {
                type: reactionType,
                download_behavior: reactionType === 'blacklist' ? 'skip' : options.downloadBehavior,
                primary_candidate_id: batchItems[0]?.candidateId ?? null,
                items: batchItems.map((item) => ({
                    candidate_id: item.candidateId,
                    url: applyMediaCleaner(item.url, mediaCleaner, {
                        candidatePageUrls: [item.referrerUrlHashAware, item.pageUrl],
                    }) ?? item.url,
                    referrer_url_hash_aware: cleanupUrlQueryParams(item.referrerUrlHashAware, referrerCleanerQueryParams)
                        ?? item.referrerUrlHashAware,
                    page_url: item.pageUrl,
                    tag_name: item.tagName,
                })),
                listing_metadata_overrides: listingMetadataOverrides,
                cookies: cookies.length > 0 ? cookies : null,
                user_agent: userAgent,
            }
            : {
                type: reactionType,
                download_behavior: reactionType === 'blacklist' ? 'skip' : options.downloadBehavior,
                url: cleanedReactionUrl,
                referrer_url_hash_aware: cleanedPageReferrerUrl,
                page_url: window.location.href,
                tag_name: isVideo ? 'video' : 'img',
                listing_metadata_overrides: listingMetadataOverrides,
                cookies: cookies.length > 0 ? cookies : null,
                user_agent: userAgent,
            };
        const requestBodyJson = JSON.stringify(requestBody);
        const endpoint = usesBatchEndpoint
            ? `${stored.atlasDomain}/api/extension/reactions/batch`
            : `${stored.atlasDomain}/api/extension/reactions`;

        let payload: unknown = null;
        const runtimeResponse = await atlasLoggedRuntimeRequest(
            endpoint,
            'POST',
            requestBody,
            () => submitReactionViaRuntime(endpoint, stored.atlasDomain, stored.apiToken, requestBody),
        );
        if (runtimeResponse !== null) {
            if (!runtimeResponse.ok) {
                return {
                    ok: false,
                    reaction: null,
                    exists: false,
                    fileId: null,
                    blacklistedAt: null,
                    downloadRequested: false,
                    shouldCloseTabAfterQueue: false,
                    downloadTransferId: null,
                    downloadStatus: null,
                    downloadProgressPercent: null,
                    downloadCloseTargets: [],
                    reverbConfig: null,
                };
            }

            payload = runtimeResponse.payload;
        } else {
            const response = await atlasLoggedFetch(endpoint, 'POST', requestBody, {
                method: 'POST',
                headers: createAtlasApiHeaders(stored.apiToken, true),
                ...createAtlasFetchAuthOptions(stored.apiToken),
                body: requestBodyJson,
            });

            if (!response.ok) {
                return {
                    ok: false,
                    reaction: null,
                    exists: false,
                    fileId: null,
                    blacklistedAt: null,
                    downloadRequested: false,
                    shouldCloseTabAfterQueue: false,
                    downloadTransferId: null,
                    downloadStatus: null,
                    downloadProgressPercent: null,
                    downloadCloseTargets: [],
                    reverbConfig: null,
                };
            }

            try {
                payload = await response.json();
            } catch {
                payload = null;
            }
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
        const blacklistedAt = getBlacklistedAtFromPayload(payload);
        const downloadRequested = downloadPayload.requested === true;
        // Keep both checks while older Atlas deployments may omit the aggregated
        // batch.download_requested flag and only report queueing per item.
        const shouldCloseTabAfterQueue = downloadRequested
            || batchDownloadRequested(payload)
            || batchQueuedDownloadRequested(payload);
        const downloadTransferId = numberOrNull(downloadPayload.transfer_id);
        const downloadStatus = stringOrNull(downloadPayload.status);
        const downloadProgressPercent = numberOrNull(downloadPayload.progress_percent);
        const downloadCloseTargets = getDownloadCloseTargets(payload, fileId);
        const reverbConfig = parseReverbConfig(rootPayload.reverb);

        if (extractedReaction.found) {
            return {
                ok: true,
                reaction: extractedReaction.reaction,
                exists: extractedExists ?? true,
                fileId,
                blacklistedAt,
                downloadRequested,
                shouldCloseTabAfterQueue,
                downloadTransferId,
                downloadStatus,
                downloadProgressPercent,
                downloadCloseTargets,
                reverbConfig,
            };
        }

        return {
            ok: true,
            reaction: reactionType === 'blacklist' ? null : reactionType,
            exists: extractedExists ?? true,
            fileId,
            blacklistedAt,
            downloadRequested,
            shouldCloseTabAfterQueue,
            downloadTransferId,
            downloadStatus,
            downloadProgressPercent,
            downloadCloseTargets,
            reverbConfig,
        };
    } catch {
        return {
            ok: false,
            reaction: null,
            exists: false,
            fileId: null,
            blacklistedAt: null,
            downloadRequested: false,
            shouldCloseTabAfterQueue: false,
            downloadTransferId: null,
            downloadStatus: null,
            downloadProgressPercent: null,
            downloadCloseTargets: [],
            reverbConfig: null,
        };
    }
}
