import { collectCookiesForUrls } from './background-cookie-runtime';
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
};

type QueueReferrerCheckPayload = {
    type: 'ATLAS_QUEUE_REFERRER_CHECK';
    atlasDomain: string;
    apiToken: string;
    normalizedReferrerUrl: string;
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

    const atlasDomain = typeof submitPayload.atlasDomain === 'string' ? submitPayload.atlasDomain.trim().replace(/\/+$/, '') : '';
    const apiToken = typeof submitPayload.apiToken === 'string' ? submitPayload.apiToken.trim() : '';
    const endpoint = typeof submitPayload.endpoint === 'string' ? submitPayload.endpoint.trim() : '';
    const body = submitPayload.body;
    const isAllowedEndpoint = endpoint === `${atlasDomain}/api/extension/reactions`
        || endpoint === `${atlasDomain}/api/extension/reactions/batch`;
    if (atlasDomain === '' || apiToken === '' || !isAllowedEndpoint || typeof body !== 'object' || body === null) {
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
        headers: {
            'Content-Type': 'application/json',
            'X-Atlas-Api-Key': apiToken,
        },
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
    sendResponse: RuntimeSendResponse,
): boolean {
    if (!message || typeof message !== 'object') {
        return false;
    }

    const queuePayload = message as QueueBadgeCheckPayload;
    if (queuePayload.type !== 'ATLAS_QUEUE_BADGE_CHECK') {
        return false;
    }

    const atlasDomain = typeof queuePayload.atlasDomain === 'string' ? queuePayload.atlasDomain.trim().replace(/\/+$/, '') : '';
    const apiToken = typeof queuePayload.apiToken === 'string' ? queuePayload.apiToken.trim() : '';
    const normalizedMediaUrl = typeof queuePayload.normalizedMediaUrl === 'string'
        ? queuePayload.normalizedMediaUrl.trim()
        : '';

    if (atlasDomain === '' || apiToken === '' || normalizedMediaUrl === '') {
        sendResponse({ ok: false, status: 0, payload: emptyBadgeCheckResult() });
        return false;
    }

    void enqueueGlobalBadgeCheck({
        atlasDomain,
        apiToken,
        normalizedMediaUrl,
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
    sendResponse: RuntimeSendResponse,
): boolean {
    if (!message || typeof message !== 'object') {
        return false;
    }

    const queuePayload = message as QueueReferrerCheckPayload;
    if (queuePayload.type !== 'ATLAS_QUEUE_REFERRER_CHECK') {
        return false;
    }

    const atlasDomain = typeof queuePayload.atlasDomain === 'string' ? queuePayload.atlasDomain.trim().replace(/\/+$/, '') : '';
    const apiToken = typeof queuePayload.apiToken === 'string' ? queuePayload.apiToken.trim() : '';
    const normalizedReferrerUrl = typeof queuePayload.normalizedReferrerUrl === 'string'
        ? queuePayload.normalizedReferrerUrl.trim()
        : '';

    if (atlasDomain === '' || apiToken === '' || normalizedReferrerUrl === '') {
        sendResponse({ ok: false, status: 0, payload: emptyReferrerCheckResult() });
        return false;
    }

    void enqueueGlobalReferrerCheck({
        atlasDomain,
        apiToken,
        normalizedReferrerUrl,
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

    const atlasDomain = typeof requestPayload.atlasDomain === 'string' ? requestPayload.atlasDomain.trim().replace(/\/+$/, '') : '';
    const apiToken = typeof requestPayload.apiToken === 'string' ? requestPayload.apiToken.trim() : '';
    const endpoint = typeof requestPayload.endpoint === 'string' ? requestPayload.endpoint.trim() : '';
    const method = requestPayload.method === 'POST' ? 'POST' : requestPayload.method === 'GET' ? 'GET' : null;
    const body = requestPayload.body;
    const requiresBody = method === 'POST';

    if (
        method === null
        || apiToken === ''
        || !isAllowedAtlasApiEndpoint(atlasDomain, endpoint, method)
        || (requiresBody && (typeof body !== 'object' || body === null))
    ) {
        sendResponse({ ok: false, status: 0, payload: null });
        return false;
    }

    const headers: Record<string, string> = {
        'X-Atlas-Api-Key': apiToken,
    };
    const init: RequestInit = {
        method,
        headers,
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
