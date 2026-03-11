import { getStoredOptions } from '../atlas-options';
import { cleanupReferrerUrl } from '../referrer-cleanup';
import { normalizeUrl, resolveReactionTargetUrl, type MediaElement } from './media-utils';
import type { BatchReactionItem } from './deviantart-batch-reaction';
import type { BadgeReactionType } from './reaction-check-queue';
import type { ReverbConfig } from '../reverb-client';
import { atlasLoggedFetch, atlasLoggedRuntimeRequest } from './atlas-request-log';
import { shouldUseKeepaliveRequest } from '../request-keepalive';

type SubmitReactionResult = {
    ok: boolean;
    reaction: BadgeReactionType | null;
    exists: boolean;
    fileId: number | null;
    downloadRequested: boolean;
    shouldCloseTabAfterQueue: boolean;
    downloadTransferId: number | null;
    downloadStatus: string | null;
    downloadProgressPercent: number | null;
    reverbConfig: ReverbConfig | null;
};

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


function batchDownloadRequested(value: unknown): boolean {
    if (!value || typeof value !== 'object') {
        return false;
    }

    const batch = (value as Record<string, unknown>).batch;
    if (!batch || typeof batch !== 'object') {
        return false;
    }

    return (batch as Record<string, unknown>).download_requested === true;
}

function batchQueuedDownloadRequested(value: unknown): boolean {
    if (!value || typeof value !== 'object') {
        return false;
    }

    const batch = (value as Record<string, unknown>).batch;
    if (!batch || typeof batch !== 'object') {
        return false;
    }

    const items = (batch as Record<string, unknown>).items;
    if (!Array.isArray(items)) {
        return false;
    }

    return items.some((entry) => {
        if (!entry || typeof entry !== 'object') {
            return false;
        }

        const download = (entry as Record<string, unknown>).download;
        return !!download && typeof download === 'object' && (download as Record<string, unknown>).requested === true;
    });
}

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
    reactionType: BadgeReactionType,
    options: SubmitBadgeReactionOptions = {},
): Promise<SubmitReactionResult> {
    const pageUrl = normalizeUrl(window.location.href);
    const reactionUrl = resolveReactionTargetUrl(media, pageUrl);
    const isVideo = media instanceof HTMLVideoElement;
    const batchItems = options.batchItems?.filter((item) => item.url.trim() !== '') ?? [];
    const usesBatchEndpoint = batchItems.length >= 2;
    if (reactionUrl === null && !usesBatchEndpoint) {
        return {
            ok: false,
            reaction: null,
            exists: false,
            fileId: null,
            downloadRequested: false,
            shouldCloseTabAfterQueue: false,
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
                shouldCloseTabAfterQueue: false,
                downloadTransferId: null,
                downloadStatus: null,
                downloadProgressPercent: null,
                reverbConfig: null,
            };
        }

        const cookieUrls = usesBatchEndpoint
            ? normalizeCookieUrls([
                ...batchItems.map((item) => item.url),
                ...batchItems.map((item) => item.pageUrl),
                ...batchItems.map((item) => item.referrerUrlHashAware),
            ])
            : normalizeCookieUrls([reactionUrl, pageUrl]);
        const cookies = await getRuntimeCookies(cookieUrls);
        const userAgent = getSafeUserAgent();
        const referrerQueryParamsToStripByDomain = stored.referrerQueryParamsToStripByDomain;
        const cleanedPageReferrerUrl = cleanupReferrerUrl(window.location.href, referrerQueryParamsToStripByDomain)
            ?? window.location.href;
        const requestBody = usesBatchEndpoint
            ? {
                type: reactionType,
                primary_candidate_id: batchItems[0]?.candidateId ?? null,
                items: batchItems.map((item) => ({
                    candidate_id: item.candidateId,
                    url: item.url,
                    referrer_url_hash_aware: cleanupReferrerUrl(item.referrerUrlHashAware, referrerQueryParamsToStripByDomain)
                        ?? item.referrerUrlHashAware,
                    page_url: item.pageUrl,
                    tag_name: item.tagName,
                })),
                cookies: cookies.length > 0 ? cookies : null,
                user_agent: userAgent,
            }
            : {
                type: reactionType,
                url: reactionUrl,
                referrer_url_hash_aware: cleanedPageReferrerUrl,
                page_url: window.location.href,
                tag_name: isVideo ? 'video' : 'img',
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
                    downloadRequested: false,
                    shouldCloseTabAfterQueue: false,
                    downloadTransferId: null,
                    downloadStatus: null,
                    downloadProgressPercent: null,
                    reverbConfig: null,
                };
            }

            payload = runtimeResponse.payload;
        } else {
            const response = await atlasLoggedFetch(endpoint, 'POST', requestBody, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Atlas-Api-Key': stored.apiToken,
                },
                body: requestBodyJson,
                keepalive: shouldUseKeepaliveRequest(requestBodyJson),
            });

            if (!response.ok) {
                return {
                    ok: false,
                    reaction: null,
                    exists: false,
                    fileId: null,
                    downloadRequested: false,
                    shouldCloseTabAfterQueue: false,
                    downloadTransferId: null,
                    downloadStatus: null,
                    downloadProgressPercent: null,
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
        const downloadRequested = downloadPayload.requested === true;
        // Keep both checks while older Atlas deployments may omit the aggregated
        // batch.download_requested flag and only report queueing per item.
        const shouldCloseTabAfterQueue = reactionType === 'dislike'
            || downloadRequested
            || batchDownloadRequested(payload)
            || batchQueuedDownloadRequested(payload);
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
                shouldCloseTabAfterQueue,
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
            shouldCloseTabAfterQueue,
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
            shouldCloseTabAfterQueue: false,
            downloadTransferId: null,
            downloadStatus: null,
            downloadProgressPercent: null,
            reverbConfig: null,
        };
    }
}
