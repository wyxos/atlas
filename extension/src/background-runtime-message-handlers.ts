import { collectCookiesForUrls } from './background-cookie-runtime';
import {
    createAtlasApiHeaders,
    createAtlasFetchAuthOptions,
    hasAtlasApiAuth,
    normalizeAtlasDomain,
} from './atlas-auth';
import {
    emptyBadgeCheckResult,
    emptyReferrerCheckResult,
    enqueueGlobalBadgeCheck,
    enqueueGlobalReferrerCheck,
} from './background-atlas-check-queue';
import {
    broadcastReferrerReactionSync,
    extractPayloadString,
    extractReactionFromPayload,
    extractReferrerReactionUrls,
    parseReactionType,
    primeSettledReferrerReactionUrls,
} from './background-referrer-reaction-sync';
import { normalizeComparableUrls } from './background-url-utils';

type RuntimeMessageSender = {
    tab?: {
        id?: number;
        active?: boolean;
        discarded?: boolean;
    };
};

type RuntimeSendResponse = (response?: unknown) => void;

type SubmitReactionPayload = {
    type: 'ATLAS_SUBMIT_REACTION';
    atlasDomain: string;
    apiToken: string;
    endpoint: string;
    body: Record<string, unknown>;
};

type AtlasApiRequestPayload = {
    type: 'ATLAS_API_REQUEST';
    atlasDomain: string;
    apiToken: string;
    endpoint: string;
    method: 'GET' | 'POST';
    body?: Record<string, unknown> | null;
};

type QueueBadgeCheckPayload = {
    type: 'ATLAS_QUEUE_BADGE_CHECK';
    atlasDomain: string;
    apiToken: string;
    normalizedMediaUrl: string;
    bypassCache?: unknown;
    pageVisibility?: unknown;
};

type QueueReferrerCheckPayload = {
    type: 'ATLAS_QUEUE_REFERRER_CHECK';
    atlasDomain: string;
    apiToken: string;
    normalizedReferrerUrl: string;
    pageVisibility?: unknown;
};

const ATLAS_CHECK_PRIORITY_NORMAL = 1;
const ATLAS_CHECK_PRIORITY_ACTIVE = 2;

type QueueRuntimeOptions = {
    cacheOnly: boolean;
    priority: number;
};

function parseJsonResponse(response: Response): Promise<unknown> {
    return response.text()
        .then((bodyText) => {
            const trimmed = bodyText.trim();
            if (trimmed === '') {
                return null;
            }

            try {
                return JSON.parse(trimmed) as unknown;
            } catch {
                return bodyText;
            }
        })
        .catch(() => null);
}

function isAllowedAtlasApiEndpoint(
    atlasDomain: string,
    endpoint: string,
    method: 'GET' | 'POST',
): boolean {
    if (atlasDomain === '') {
        return false;
    }

    return method === 'GET' && endpoint === `${atlasDomain}/api/extension/ping`;
}

function resolveQueueRuntimeOptions(payload: { pageVisibility?: unknown }, sender: RuntimeMessageSender): QueueRuntimeOptions {
    const visibility = typeof payload.pageVisibility === 'string' ? payload.pageVisibility : null;
    const isHiddenPage = visibility === 'hidden';
    const isActiveTab = sender.tab?.active === true;
    const isDiscardedTab = sender.tab?.discarded === true;

    return {
        cacheOnly: isHiddenPage || isDiscardedTab,
        priority: isActiveTab || visibility === 'visible'
            ? ATLAS_CHECK_PRIORITY_ACTIVE
            : ATLAS_CHECK_PRIORITY_NORMAL,
    };
}

export function handleGetUrlCookiesRuntimeMessage(
    message: unknown,
    sendResponse: RuntimeSendResponse,
): boolean {
    if (!message || typeof message !== 'object') {
        return false;
    }

    const payload = message as { type?: unknown; urls?: unknown };
    if (payload.type !== 'ATLAS_GET_URL_COOKIES') {
        return false;
    }

    const urls = normalizeComparableUrls(payload.urls);
    if (urls.length === 0) {
        sendResponse({ cookies: [] });
        return false;
    }

    void collectCookiesForUrls(urls)
        .then((cookies) => {
            sendResponse({ cookies });
        })
        .catch(() => {
            sendResponse({ cookies: [] });
        });

    return true;
}

export function handleSubmitReactionRuntimeMessage(
    message: unknown,
    sender: RuntimeMessageSender,
    sendResponse: RuntimeSendResponse,
): boolean {
    if (!message || typeof message !== 'object') {
        return false;
    }

    const submitPayload = message as SubmitReactionPayload;
    if (submitPayload.type !== 'ATLAS_SUBMIT_REACTION') {
        return false;
    }

    const atlasDomain = typeof submitPayload.atlasDomain === 'string' ? normalizeAtlasDomain(submitPayload.atlasDomain) : '';
    const apiToken = typeof submitPayload.apiToken === 'string' ? submitPayload.apiToken.trim() : '';
    const endpoint = typeof submitPayload.endpoint === 'string' ? submitPayload.endpoint.trim() : '';
    const body = submitPayload.body;
    const isAllowedEndpoint = endpoint === `${atlasDomain}/api/extension/reactions`
        || endpoint === `${atlasDomain}/api/extension/reactions/batch`;
    if (atlasDomain === '' || !hasAtlasApiAuth(atlasDomain, apiToken) || !isAllowedEndpoint || typeof body !== 'object' || body === null) {
        sendResponse({ ok: false, status: 0, payload: null });
        return false;
    }

    const referrerReactionUrls = extractReferrerReactionUrls(body);
    const senderTabId = sender.tab?.id;
    if (referrerReactionUrls.length > 0) {
        broadcastReferrerReactionSync({
            type: 'ATLAS_REFERRER_REACTION_SYNC',
            phase: 'pending',
            urls: referrerReactionUrls,
        }, senderTabId);
    }

    void fetch(endpoint, {
        method: 'POST',
        headers: createAtlasApiHeaders(apiToken, true),
        ...createAtlasFetchAuthOptions(apiToken),
        body: JSON.stringify(body),
    })
        .then(async (response) => {
            let responsePayload: unknown = null;
            try {
                responsePayload = await response.json();
            } catch {
                responsePayload = null;
            }

            if (referrerReactionUrls.length > 0) {
                if (response.ok) {
                    const reaction = extractReactionFromPayload(responsePayload) ?? parseReactionType(body.type);
                    const reactedAt = extractPayloadString(responsePayload, 'reacted_at', 'reactedAt');
                    const downloadedAt = extractPayloadString(responsePayload, 'downloaded_at', 'downloadedAt');
                    const blacklistedAt = extractPayloadString(responsePayload, 'blacklisted_at', 'blacklistedAt');

                    primeSettledReferrerReactionUrls(
                        referrerReactionUrls,
                        reaction,
                        reactedAt,
                        downloadedAt,
                        blacklistedAt,
                    );
                    broadcastReferrerReactionSync({
                        type: 'ATLAS_REFERRER_REACTION_SYNC',
                        phase: 'settled',
                        urls: referrerReactionUrls,
                        reaction,
                        reactedAt,
                        downloadedAt,
                        blacklistedAt,
                    }, senderTabId);
                } else {
                    broadcastReferrerReactionSync({
                        type: 'ATLAS_REFERRER_REACTION_SYNC',
                        phase: 'failed',
                        urls: referrerReactionUrls,
                    }, senderTabId);
                }
            }

            sendResponse({
                ok: response.ok,
                status: response.status,
                payload: responsePayload,
            });
        })
        .catch(() => {
            if (referrerReactionUrls.length > 0) {
                broadcastReferrerReactionSync({
                    type: 'ATLAS_REFERRER_REACTION_SYNC',
                    phase: 'failed',
                    urls: referrerReactionUrls,
                }, senderTabId);
            }

            sendResponse({ ok: false, status: 0, payload: null });
        });

    return true;
}

export function handleQueuedBadgeCheckRuntimeMessage(
    message: unknown,
    sender: RuntimeMessageSender,
    sendResponse: RuntimeSendResponse,
): boolean {
    if (!message || typeof message !== 'object') {
        return false;
    }

    const queuePayload = message as QueueBadgeCheckPayload;
    if (queuePayload.type !== 'ATLAS_QUEUE_BADGE_CHECK') {
        return false;
    }

    const atlasDomain = typeof queuePayload.atlasDomain === 'string' ? normalizeAtlasDomain(queuePayload.atlasDomain) : '';
    const apiToken = typeof queuePayload.apiToken === 'string' ? queuePayload.apiToken.trim() : '';
    const normalizedMediaUrl = typeof queuePayload.normalizedMediaUrl === 'string'
        ? queuePayload.normalizedMediaUrl.trim()
        : '';

    if (atlasDomain === '' || !hasAtlasApiAuth(atlasDomain, apiToken) || normalizedMediaUrl === '') {
        sendResponse({ ok: false, status: 0, payload: emptyBadgeCheckResult() });
        return false;
    }

    const queueOptions = resolveQueueRuntimeOptions(queuePayload, sender);
    void enqueueGlobalBadgeCheck({
        atlasDomain,
        apiToken,
        normalizedMediaUrl,
        bypassCache: queuePayload.bypassCache === true,
        cacheOnly: queueOptions.cacheOnly,
        priority: queueOptions.priority,
    })
        .then((response) => {
            sendResponse(response);
        })
        .catch(() => {
            sendResponse({ ok: false, status: 0, payload: emptyBadgeCheckResult() });
        });

    return true;
}

export function handleQueuedReferrerCheckRuntimeMessage(
    message: unknown,
    sender: RuntimeMessageSender,
    sendResponse: RuntimeSendResponse,
): boolean {
    if (!message || typeof message !== 'object') {
        return false;
    }

    const queuePayload = message as QueueReferrerCheckPayload;
    if (queuePayload.type !== 'ATLAS_QUEUE_REFERRER_CHECK') {
        return false;
    }

    const atlasDomain = typeof queuePayload.atlasDomain === 'string' ? normalizeAtlasDomain(queuePayload.atlasDomain) : '';
    const apiToken = typeof queuePayload.apiToken === 'string' ? queuePayload.apiToken.trim() : '';
    const normalizedReferrerUrl = typeof queuePayload.normalizedReferrerUrl === 'string'
        ? queuePayload.normalizedReferrerUrl.trim()
        : '';

    if (atlasDomain === '' || !hasAtlasApiAuth(atlasDomain, apiToken) || normalizedReferrerUrl === '') {
        sendResponse({ ok: false, status: 0, payload: emptyReferrerCheckResult() });
        return false;
    }

    const queueOptions = resolveQueueRuntimeOptions(queuePayload, sender);
    void enqueueGlobalReferrerCheck({
        atlasDomain,
        apiToken,
        normalizedReferrerUrl,
        cacheOnly: queueOptions.cacheOnly,
        priority: queueOptions.priority,
    })
        .then((response) => {
            sendResponse(response);
        })
        .catch(() => {
            sendResponse({ ok: false, status: 0, payload: emptyReferrerCheckResult() });
        });

    return true;
}

export function handleAtlasApiRequestRuntimeMessage(
    message: unknown,
    sendResponse: RuntimeSendResponse,
): boolean {
    if (!message || typeof message !== 'object') {
        return false;
    }

    const requestPayload = message as AtlasApiRequestPayload;
    if (requestPayload.type !== 'ATLAS_API_REQUEST') {
        return false;
    }

    const atlasDomain = typeof requestPayload.atlasDomain === 'string' ? normalizeAtlasDomain(requestPayload.atlasDomain) : '';
    const apiToken = typeof requestPayload.apiToken === 'string' ? requestPayload.apiToken.trim() : '';
    const endpoint = typeof requestPayload.endpoint === 'string' ? requestPayload.endpoint.trim() : '';
    const method = requestPayload.method === 'POST' ? 'POST' : requestPayload.method === 'GET' ? 'GET' : null;
    const body = requestPayload.body;
    const requiresBody = method === 'POST';

    if (
        method === null
        || !hasAtlasApiAuth(atlasDomain, apiToken)
        || !isAllowedAtlasApiEndpoint(atlasDomain, endpoint, method)
        || (requiresBody && (typeof body !== 'object' || body === null))
    ) {
        sendResponse({ ok: false, status: 0, payload: null });
        return false;
    }

    const headers: Record<string, string> = createAtlasApiHeaders(apiToken);
    const init: RequestInit = {
        method,
        headers,
        ...createAtlasFetchAuthOptions(apiToken),
    };

    if (method === 'POST') {
        headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(body);
    }

    void fetch(endpoint, init)
        .then(async (response) => {
            sendResponse({
                ok: response.ok,
                status: response.status,
                payload: await parseJsonResponse(response),
            });
        })
        .catch(() => {
            sendResponse({ ok: false, status: 0, payload: null });
        });

    return true;
}
